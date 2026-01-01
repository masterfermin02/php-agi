<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi;

/**
 * Asterisk Manager class
 *
 * @link http://www.voip-info.org/wiki-Asterisk+config+manager.conf
 * @link http://www.voip-info.org/wiki-Asterisk+manager+API
 *
 * @example examples/sip_show_peer.php Get information about a sip peer
 */
class AgiAsteriskManager
{
    /**
     * Config variables
     */
    public array $config = [];

    /**
     * Socket
     * (resource)
     *
     * @var mixed
     */
    public $socket;

    /**
     * Server we are connected to
     */
    public ?string $server = null;

    /**
     * Port on the server we are connected to
     */
    public ?int $port = null;

    /**
     * Parent AGI
     */
    public ?Agi $pagi = null;

    /**
     * Event Handlers
     */
    private array $event_handlers = [];

    private ?string $_buffer = null;

    /**
     * Whether we're successfully logged in
     */
    private bool $_logged_in = false;

    public function setPagi(Agi &$agi): void
    {
        $this->pagi = $agi;
    }

    /**
     * Constructor
     *
     * @param  string|null  $config  is the name of the config file to parse or a parent agi from which to read the config
     * @param  array  $optconfig  is an array of configuration vars and vals, stuffed into $this->config['asmanager']
     */
    public function __construct($config = null, array $optconfig = [])
    {
        // load config
        if (! is_null($config) && file_exists($config)) {
            $this->config = parse_ini_file($config, true);
        } elseif (file_exists(Constants::DEFAULT_PHPAGI_CONFIG)) {
            $this->config = parse_ini_file(Constants::DEFAULT_PHPAGI_CONFIG, true);
        }

        // If optconfig is specified, stuff vals and vars into 'asmanager' config array.
        foreach ($optconfig as $var => $val) {
            $this->config['asmanager'][$var] = $val;
        }

        // add default values to config for uninitialized values
        if (! isset($this->config['asmanager']['server'])) {
            $this->config['asmanager']['server'] = 'localhost';
        }
        if (! isset($this->config['asmanager']['port'])) {
            $this->config['asmanager']['port'] = 5038;
        }
        if (! isset($this->config['asmanager']['username'])) {
            $this->config['asmanager']['username'] = 'phpagi';
        }
        if (! isset($this->config['asmanager']['secret'])) {
            $this->config['asmanager']['secret'] = 'phpagi';
        }
        if (! isset($this->config['asmanager']['write_log'])) {
            $this->config['asmanager']['write_log'] = false;
        }
    }

    /**
     * Send a request
     *
     * @return array of parameters
     */
    public function send_request(string $action, array $parameters = []): array
    {
        $req = "Action: $action\r\n";
        $actionid = null;
        foreach ($parameters as $var => $val) {
            if (is_array($val)) {
                foreach ($val as $line) {
                    $req .= "$var: $line\r\n";
                }
            } else {
                $req .= "$var: $val\r\n";
                if (strtolower((string) $var) === 'actionid') {
                    $actionid = $val;
                }
            }
        }
        if (! $actionid) {
            $actionid = $this->ActionID();
            $req .= "ActionID: $actionid\r\n";
        }
        $req .= "\r\n";

        fwrite($this->socket, $req);

        return $this->wait_response(false, $actionid);
    }

    public function read_one_msg(bool $allow_timeout = false): array
    {
        $type = null;

        do {
            $buf = fgets($this->socket, 4096);
            if ($buf === false) {
                throw new \Exception('Error reading from AMI socket');
            }
            $this->_buffer .= $buf;

            $pos = strpos($this->_buffer, "\r\n\r\n");
            if ($pos !== false) {
                // there's a full message in the buffer
                break;
            }
        } while (! feof($this->socket));

        $msg = substr($this->_buffer, 0, $pos);
        $this->_buffer = substr($this->_buffer, $pos + 4);

        $msgarr = explode("\r\n", $msg);

        $parameters = [];

        $r = explode(': ', $msgarr[0]);
        $type = strtolower($r[0]);

        if (isset($r[1]) && ($r[1] == 'Success' || $r[1] == 'Follows')) {
            $m = explode(': ', $msgarr[2] ?? '');
            $msgarr_tmp = $msgarr;
            $str = array_pop($msgarr);
            $lastline = strpos($str, '--END COMMAND--');
            if ($lastline !== false) {
                $parameters['data'] = substr($str, 0, $lastline - 1);
                // cut '\n' too
            } elseif (isset($m[1]) && $m[1] == 'Command output follows') {
                $n = 3;
                $c = count($msgarr_tmp) - 1;
                $output = explode(': ', $msgarr_tmp[3] ?? '');
                if (($output[1] ?? '') !== '' && ($output[1] ?? '') !== '0') {
                    $data = $output[1];
                    while ($n++ < $c) {
                        $output = explode(': ', $msgarr_tmp[$n] ?? '');
                        if (($output[1] ?? '') !== '' && ($output[1] ?? '') !== '0') {
                            $data .= "\n".$output[1];
                        }
                    }
                    $parameters['data'] = $data;
                }
            }
        }

        foreach ($msgarr as $str) {
            $kv = explode(':', $str, 2);
            if (! isset($kv[1])) {
                $kv[1] = '';
            }
            $key = trim($kv[0]);
            $val = trim($kv[1]);
            $parameters[$key] = $val;
        }

        // process response
        switch ($type) {
            case '': // timeout occured
                $timeout = $allow_timeout;
                break;
            case 'event':
                $this->process_event($parameters);
                break;
            case 'response':
                break;
            default:
                $this->log('Unhandled response packet from Manager: '.print_r($parameters, true));
                break;
        }

        return $parameters;
    }

    /**
     * Wait for a response
     *
     * If a request was just sent, this will return the response.
     * Otherwise, it will loop forever, handling events.
     *
     * XXX this code is slightly better then the original one
     * however it's still totally screwed up and needs to be rewritten,
     * for two reasons at least:
     * 1. it does not handle socket errors in any way
     * 2. it is terribly synchronous, esp. with eventlists,
     *    i.e. your code is blocked on waiting until full responce is received
     *
     * @param  bool  $allow_timeout  if the socket times out, return an empty array
     * @return array of parameters, empty on timeout
     */
    public function wait_response(bool $allow_timeout = false, $actionid = null): array
    {
        $res = [];
        if ($actionid) {
            do {
                $res = $this->read_one_msg($allow_timeout);
            } while (! (isset($res['ActionID']) && $res['ActionID'] == $actionid));
        } else {
            return $this->read_one_msg($allow_timeout);
        }

        if (isset($res['EventList']) && $res['EventList'] == 'start') {
            $evlist = [];
            do {
                $res = $this->wait_response(false, $actionid);
                if (isset($res['EventList']) && $res['EventList'] == 'Complete') {
                    break;
                } else {
                    $evlist[] = $res;
                }
            } while (true);
            $res['events'] = $evlist;
        }

        return $res;
    }

    /**
     * Connect to Asterisk
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     *
     * @param  string  $server
     * @param  string  $username
     * @param  string  $secret
     * @return bool true on success
     */
    public function connect($server = null, $username = null, $secret = null): bool
    {
        // use config if not specified
        if (is_null($server)) {
            $server = $this->config['asmanager']['server'];
        }
        if (is_null($username)) {
            $username = $this->config['asmanager']['username'];
        }
        if (is_null($secret)) {
            $secret = $this->config['asmanager']['secret'];
        }

        // get port from server if specified
        if (str_contains((string) $server, ':')) {
            $c = explode(':', (string) $server);
            $this->server = $c[0];
            $this->port = (int) $c[1];
        } else {
            $this->server = $server;
            $this->port = (int) $this->config['asmanager']['port'];
        }

        // connect the socket
        $errno = $errstr = null;
        $this->socket = @fsockopen($this->server, $this->port, $errno, $errstr);
        if ($this->socket == false) {
            $this->log("Unable to connect to manager {$this->server}:{$this->port} ($errno): $errstr");

            return false;
        }

        // read the header
        $str = fgets($this->socket);
        if ($str == false) {
            // a problem.
            $this->log('Asterisk Manager header not received.');

            return false;
        }

        // login
        $res = $this->send_request('login', ['Username' => $username, 'Secret' => $secret]);
        if (($res['Response'] ?? '') != 'Success') {
            $this->_logged_in = false;
            $this->log('Failed to login.');
            $this->disconnect();

            return false;
        }
        $this->_logged_in = true;

        return true;
    }

    /**
     * Disconnect
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     */
    public function disconnect(): void
    {
        if ($this->_logged_in) {
            $this->logoff();
        }
        fclose($this->socket);
    }

    // *********************************************************************************************************
    // **                       COMMANDS                                                                      **
    // *********************************************************************************************************

    /**
     * Set Absolute Timeout
     *
     * Hangup a channel after a certain time.
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
     *
     * @param  string  $channel  Channel name to hangup
     * @param  int  $timeout  Maximum duration of the call (sec)
     */
    public function AbsoluteTimeout($channel, $timeout): array
    {
        return $this->send_request('AbsoluteTimeout', ['Channel' => $channel, 'Timeout' => $timeout]);
    }

    /**
     * Change monitoring filename of a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
     *
     * @param  string  $channel  the channel to record.
     * @param  string  $file  the new name of the file created in the monitor spool directory.
     */
    public function ChangeMonitor($channel, $file): array
    {
        return $this->send_request('ChangeMonitor', ['Channel' => $channel, 'File' => $file]);
    }

    /**
     * Execute Command
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
     * @link http://www.voip-info.org/wiki-Asterisk+CLI
     *
     * @param  string  $command
     * @param  string  $actionid  message matching variable
     */
    public function Command($command, $actionid = null): array
    {
        $parameters = ['Command' => $command];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Command', $parameters);
    }

    /**
     * Enable/Disable sending of events to this manager
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
     *
     * @param  string  $eventmask  is either 'on', 'off', or 'system,call,log'
     */
    public function Events($eventmask): array
    {
        return $this->send_request('Events', ['EventMask' => $eventmask]);
    }

    /**
     *  Generate random ActionID
     **/
    public function ActionID(): string
    {
        // use a compact, predictable ActionID
        return 'A'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT).uniqid('-', true);
    }

    /**
     *  DBGet
     *  http://www.voip-info.org/wiki/index.php?page=Asterisk+Manager+API+Action+DBGet
     *
     * @param  string  $family  key family
     * @param  string  $key  key name
     **/
    public function DBGet($family, $key, $actionid = null)
    {
        $parameters = ['Family' => $family, 'Key' => $key];
        if ($actionid == null) {
            $actionid = $this->ActionID();
        }
        $parameters['ActionID'] = $actionid;
        $response = $this->send_request('DBGet', $parameters);
        if (($response['Response'] ?? '') == 'Success') {
            $response = $this->wait_response(false, $actionid);

            return $response['Val'] ?? '';
        }

        return '';
    }

    /**
     * Check Extension Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
     *
     * @param  string  $exten  Extension to check state on
     * @param  string  $context  Context for extension
     * @param  string  $actionid  message matching variable
     */
    public function ExtensionState($exten, $context, $actionid = null): array
    {
        $parameters = ['Exten' => $exten, 'Context' => $context];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('ExtensionState', $parameters);
    }

    /**
     * Gets a Channel Variable
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     *
     * @param  string  $channel  Channel to read variable from
     * @param  string  $variable
     * @param  string  $actionid  message matching variable
     */
    public function GetVar($channel, $variable, $actionid = null): array
    {
        $parameters = ['Channel' => $channel, 'Variable' => $variable];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('GetVar', $parameters);
    }

    /**
     * Hangup Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
     *
     * @param  string  $channel  The channel name to be hungup
     */
    public function Hangup($channel): array
    {
        return $this->send_request('Hangup', ['Channel' => $channel]);
    }

    /**
     * List IAX Peers
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
     */
    public function IAXPeers(): array
    {
        return $this->send_request('IAXPeers');
    }

    /**
     * List available manager commands
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
     *
     * @param  string  $actionid  message matching variable
     */
    public function ListCommands($actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('ListCommands', ['ActionID' => $actionid]);
        }

        return $this->send_request('ListCommands');
    }

    /**
     * Logoff Manager
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
     */
    public function Logoff(): array
    {
        return $this->send_request('Logoff');
    }

    /**
     * Check Mailbox Message Count
     *
     * Returns number of new and old messages.
     *   Message: Mailbox Message Count
     *   Mailbox: <mailboxid>
     *   NewMessages: <count>
     *   OldMessages: <count>
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
     *
     * @param  string  $mailbox  Full mailbox ID <mailbox>@<vm-context>
     * @param  string  $actionid  message matching variable
     */
    public function MailboxCount($mailbox, $actionid = null): array
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('MailboxCount', $parameters);
    }

    /**
     * Check Mailbox
     *
     * Returns number of messages.
     *   Message: Mailbox Status
     *   Mailbox: <mailboxid>
     *   Waiting: <count>
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
     *
     * @param  string  $mailbox  Full mailbox ID <mailbox>@<vm-context>
     * @param  string  $actionid  message matching variable
     */
    public function MailboxStatus($mailbox, $actionid = null): array
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('MailboxStatus', $parameters);
    }

    /**
     * Monitor a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
     *
     * @param  string  $channel
     * @param  string  $file
     * @param  string  $format
     * @param  bool  $mix
     */
    public function Monitor($channel, $file = null, $format = null, $mix = null): array
    {
        $parameters = ['Channel' => $channel];
        if ($file) {
            $parameters['File'] = $file;
        }
        if ($format) {
            $parameters['Format'] = $format;
        }
        if (! is_null($file)) {
            $parameters['Mix'] = ($mix) ? 'true' : 'false';
        }

        return $this->send_request('Monitor', $parameters);
    }

    /**
     * Originate Call
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Originate
     *
     * @param  string  $channel  Channel name to call
     * @param  string  $exten  Extension to use (requires 'Context' and 'Priority')
     * @param  string  $context  Context to use (requires 'Exten' and 'Priority')
     * @param  string  $priority  Priority to use (requires 'Exten' and 'Context')
     * @param  string  $application  Application to use
     * @param  string  $data  Data to use (requires 'Application')
     * @param  int  $timeout  How long to wait for call to be answered (in ms)
     * @param  string  $callerid  Caller ID to be set on the outgoing channel
     * @param  string  $variable  Channel variable to set (VAR1=value1|VAR2=value2)
     * @param  string  $account  Account code
     * @param  bool  $async  true fast origination
     * @param  string  $actionid  message matching variable
     */
    public function Originate($channel,
        $exten = null, $context = null, $priority = null,
        $application = null, $data = null,
        $timeout = null, $callerid = null, $variable = null, $account = null, $async = null, $actionid = null): array
    {
        $parameters = ['Channel' => $channel];

        if ($exten) {
            $parameters['Exten'] = $exten;
        }
        if ($context) {
            $parameters['Context'] = $context;
        }
        if ($priority) {
            $parameters['Priority'] = $priority;
        }

        if ($application) {
            $parameters['Application'] = $application;
        }
        if ($data) {
            $parameters['Data'] = $data;
        }

        if ($timeout) {
            $parameters['Timeout'] = $timeout;
        }
        if ($callerid) {
            $parameters['CallerID'] = $callerid;
        }
        if ($variable) {
            $parameters['Variable'] = $variable;
        }
        if ($account) {
            $parameters['Account'] = $account;
        }
        if (! is_null($async)) {
            $parameters['Async'] = ($async) ? 'true' : 'false';
        }
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Originate', $parameters);
    }

    /**
     * List parked calls
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
     *
     * @param  string  $actionid  message matching variable
     */
    public function ParkedCalls($actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('ParkedCalls', ['ActionID' => $actionid]);
        }

        return $this->send_request('ParkedCalls');
    }

    /**
     * Ping
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
     */
    public function Ping(): array
    {
        return $this->send_request('Ping');
    }

    /**
     * Queue Add
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
     *
     * @param  string  $queue
     * @param  string  $interface
     * @param  int  $penalty
     * @param  string  $memberName
     */
    public function QueueAdd($queue, $interface, $penalty = 0, $memberName = false): array
    {
        $parameters = ['Queue' => $queue, 'Interface' => $interface];
        if ($penalty) {
            $parameters['Penalty'] = $penalty;
        }
        if ($memberName) {
            $parameters['MemberName'] = $memberName;
        }

        return $this->send_request('QueueAdd', $parameters);
    }

    /**
     * Queue Remove
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
     *
     * @param  string  $queue
     * @param  string  $interface
     */
    public function QueueRemove($queue, $interface): array
    {
        return $this->send_request('QueueRemove', ['Queue' => $queue, 'Interface' => $interface]);
    }

    public function QueueReload(): array
    {
        return $this->send_request('QueueReload');
    }

    /**
     * Queues
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
     */
    public function Queues(): array
    {
        return $this->send_request('Queues');
    }

    /**
     * Queue Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
     *
     * @param  string  $actionid  message matching variable
     */
    public function QueueStatus($actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('QueueStatus', ['ActionID' => $actionid]);
        }

        return $this->send_request('QueueStatus');
    }

    /**
     * Redirect
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
     *
     * @param  string  $channel
     * @param  string  $extrachannel
     * @param  string  $exten
     * @param  string  $context
     * @param  string  $priority
     */
    public function Redirect($channel, $extrachannel, $exten, $context, $priority): array
    {
        return $this->send_request('Redirect', ['Channel' => $channel, 'ExtraChannel' => $extrachannel, 'Exten' => $exten,
            'Context' => $context, 'Priority' => $priority]);
    }

    public function Atxfer($channel, $exten, $context, $priority): array
    {
        return $this->send_request('Atxfer', ['Channel' => $channel, 'Exten' => $exten,
            'Context' => $context, 'Priority' => $priority]);
    }

    /**
     * Set the CDR UserField
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
     *
     * @param  string  $userfield
     * @param  string  $channel
     * @param  string  $append
     */
    public function SetCDRUserField($userfield, $channel, $append = null): array
    {
        $parameters = ['UserField' => $userfield, 'Channel' => $channel];
        if ($append) {
            $parameters['Append'] = $append;
        }

        return $this->send_request('SetCDRUserField', $parameters);
    }

    /**
     * Set Channel Variable
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
     *
     * @param  string  $channel  Channel to set variable for
     * @param  string  $variable  name
     * @param  string  $value
     */
    public function SetVar($channel, $variable, $value): array
    {
        return $this->send_request('SetVar', ['Channel' => $channel, 'Variable' => $variable, 'Value' => $value]);
    }

    /**
     * Channel Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
     *
     * @param  string  $channel
     * @param  string  $actionid  message matching variable
     */
    public function Status($channel, $actionid = null): array
    {
        $parameters = ['Channel' => $channel];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Status', $parameters);
    }

    /**
     * Stop monitoring a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
     *
     * @param  string  $channel
     */
    public function StopMonitor($channel): array
    {
        return $this->send_request('StopMonitor', ['Channel' => $channel]);
    }

    /**
     * Dial over Zap channel while offhook
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
     *
     * @param  string  $zapchannel
     * @param  string  $number
     */
    public function ZapDialOffhook($zapchannel, $number): array
    {
        return $this->send_request('ZapDialOffhook', ['ZapChannel' => $zapchannel, 'Number' => $number]);
    }

    /**
     * Toggle Zap channel Do Not Disturb status OFF
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
     *
     * @param  string  $zapchannel
     */
    public function ZapDNDoff($zapchannel): array
    {
        return $this->send_request('ZapDNDoff', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Toggle Zap channel Do Not Disturb status ON
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
     *
     * @param  string  $zapchannel
     */
    public function ZapDNDon($zapchannel): array
    {
        return $this->send_request('ZapDNDon', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Hangup Zap Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
     *
     * @param  string  $zapchannel
     */
    public function ZapHangup($zapchannel): array
    {
        return $this->send_request('ZapHangup', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Transfer Zap Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
     *
     * @param  string  $zapchannel
     */
    public function ZapTransfer($zapchannel): array
    {
        return $this->send_request('ZapTransfer', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Zap Show Channels
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
     *
     * @param  string  $actionid  message matching variable
     */
    public function ZapShowChannels($actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('ZapShowChannels', ['ActionID' => $actionid]);
        }

        return $this->send_request('ZapShowChannels');
    }

    // *********************************************************************************************************
    // **                       MISC                                                                          **
    // *********************************************************************************************************

    /*
     * Log a message
     *
     * @param string $message
     * @param integer $level from 1 to 4
     */
    public function log(string $message, $level = 1): void
    {
        if ($this->pagi != false) {
            $this->pagi->conlog($message, $level);
        } elseif ($this->config['asmanager']['write_log']) {
            error_log(date('r').' - '.$message);
        }
    }

    /**
     * Add event handler
     *
     * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
     *   Link - Fired when two voice channels are linked together and voice data exchange commences.
     *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
     *   Newexten -
     *   Hangup -
     *   Newchannel -
     *   Newstate -
     *   Reload - Fired when the "RELOAD" console command is executed.
     *   Shutdown -
     *   ExtensionStatus -
     *   Rename -
     *   Newcallerid -
     *   Alarm -
     *   AlarmClear -
     *   Agentcallbacklogoff -
     *   Agentcallbacklogin -
     *   Agentlogoff -
     *   MeetmeJoin -
     *   MessageWaiting -
     *   join -
     *   leave -
     *   AgentCalled -
     *   ParkedCall - Fired after ParkedCalls
     *   Cdr -
     *   ParkedCallsComplete -
     *   QueueParams -
     *   QueueMember -
     *   QueueStatusEnd -
     *   Status -
     *   StatusComplete -
     *   ZapShowChannels - Fired after ZapShowChannels
     *   ZapShowChannelsComplete -
     *
     * @param  string  $event  type or * for default handler
     * @param  string  $callback  function
     * @return bool sucess
     */
    public function add_event_handler($event, $callback): bool
    {
        $event = strtolower($event);
        if (isset($this->event_handlers[$event])) {
            $this->log("$event handler is already defined, not over-writing.");

            return false;
        }
        $this->event_handlers[$event] = $callback;

        return true;
    }

    /**
     *   Remove event handler
     *
     * @param  string  $event  type or * for default handler
     * @return bool sucess
     **/
    public function remove_event_handler($event): bool
    {
        $event = strtolower($event);
        if (isset($this->event_handlers[$event])) {
            unset($this->event_handlers[$event]);

            return true;
        }
        $this->log("$event handler is not defined.");

        return false;
    }

    /**
     * Process event
     *
     * @return mixed result of event handler or false if no handler was found
     */
    public function process_event(array $parameters)
    {
        $ret = false;
        $e = strtolower((string) $parameters['Event']);
        $this->log("Got event.. $e");

        $handler = '';
        if (isset($this->event_handlers[$e])) {
            $handler = $this->event_handlers[$e];
        } elseif (isset($this->event_handlers['*'])) {
            $handler = $this->event_handlers['*'];
        }

        if (function_exists($handler)) {
            $this->log("Execute handler $handler");
            $ret = $handler($e, $parameters, $this->server, $this->port);
        } elseif (is_array($handler)) {
            $ret = call_user_func($handler, $e, $parameters, $this->server, $this->port);
        } else {
            $this->log("No event handler for event '$e'");
        }

        return $ret;
    }
}
