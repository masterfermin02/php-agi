<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi;

/**
 * AGI class
 *
 * @link http://www.voip-info.org/wiki-Asterisk+agi
 *
 * @example examples/dtmf.php Get DTMF tones from the user and say the digits
 * @example examples/input.php Get text input from the user and say it back
 * @example examples/ping.php Ping an IP address
 */
class Agi
{
    // Request variables read in on initialization.
    public array $request = [];

    // Config variables
    public array $config = [];

    // Asterisk Manager
    public ?AgiAsteriskManager $asmanager = null;

    // Input/Output/Audio streams (resources) — cannot type as 'resource' in property
    private $in;

    private $out;

    public $audio;

    // Application option delimiter
    public string $option_delim = ',';

    /**
     * Constructor
     *
     * @param  string|null  $config  is the name of the config file to parse
     * @param  array  $optconfig  is an array of configuration vars and vals, stuffed into $this->config['phpagi']
     */
    public function __construct(
        ?string $config = null,
        array $optconfig = []
    ) {
        // load config
        if (! is_null($config) && file_exists($config)) {
            $this->config = parse_ini_file($config, true);
        } elseif (file_exists(Constants::DEFAULT_PHPAGI_CONFIG)) {
            $this->config = parse_ini_file(Constants::DEFAULT_PHPAGI_CONFIG, true);
        }

        // If optconfig is specified, stuff vals and vars into 'phpagi' config array.
        foreach ($optconfig as $var => $val) {
            $this->config['phpagi'][$var] = $val;
        }

        // add default values to config for uninitialized values
        if (! isset($this->config['phpagi']['error_handler'])) {
            $this->config['phpagi']['error_handler'] = true;
        }
        if (! isset($this->config['phpagi']['debug'])) {
            $this->config['phpagi']['debug'] = false;
        }
        if (! isset($this->config['phpagi']['admin'])) {
            $this->config['phpagi']['admin'] = null;
        }
        if (! isset($this->config['phpagi']['tempdir'])) {
            $this->config['phpagi']['tempdir'] = Constants::AST_TMP_DIR;
        }

        // festival TTS config
        if (! isset($this->config['festival']['text2wave'])) {
            $this->config['festival']['text2wave'] = $this->which('text2wave');
        }

        // swift TTS config
        if (! isset($this->config['cepstral']['swift'])) {
            $this->config['cepstral']['swift'] = $this->which('swift');
        }

        ob_implicit_flush(true);

        // open stdin & stdout
        $this->in = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        $this->out = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

        // initialize error handler
        if ($this->config['phpagi']['error_handler'] == true) {
            set_error_handler(phpagi_error_handler(...));
            global $phpagi_error_handler_email;
            $phpagi_error_handler_email = $this->config['phpagi']['admin'];
            error_reporting(E_ALL);
        }

        // make sure temp folder exists
        $this->make_folder($this->config['phpagi']['tempdir']);

        // read the request
        $str = fgets($this->in);
        while ($str != "\n") {
            $this->request[substr($str, 0, strpos($str, ':'))] = trim(substr($str, strpos($str, ':') + 1));
            $str = fgets($this->in);
        }

        // open audio if eagi detected
        if (($this->request['agi_enhanced'] ?? '') == '1.0') {
            if (file_exists('/proc/'.getmypid().'/fd/3')) {
                $this->audio = fopen('/proc/'.getmypid().'/fd/3', 'r');
            } elseif (file_exists('/dev/fd/3')) {
                // may need to mount fdescfs
                $this->audio = fopen('/dev/fd/3', 'r');
            } else {
                $this->conlog('Unable to open audio stream');
            }

            if ($this->audio) {
                stream_set_blocking($this->audio, false);
            }
        }

        $this->conlog('AGI Request:');
        $this->conlog(print_r($this->request, true));
        $this->conlog('PHPAGI internal configuration:');
        $this->conlog(print_r($this->config, true));
    }

    // *********************************************************************************************************
    // **                             COMMANDS                                                                                            **
    // *********************************************************************************************************

    /**
     * Answer channel if not already in answer state.
     *
     * @link http://www.voip-info.org/wiki-answer
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     *
     * @return array, see evaluate for return information.  ['result'] is 0 on success, -1 on failure.
     */
    public function answer(): array
    {
        return $this->evaluate('ANSWER');
    }

    /**
     * Get the status of the specified channel. If no channel name is specified, return the status of the current channel.
     *
     * @link http://www.voip-info.org/wiki-channel+status
     *
     * @param  string  $channel
     * @return array, see evaluate for return information. ['data'] contains description.
     * @return mixed[]
     */
    public function channel_status($channel = ''): array
    {
        $ret = $this->evaluate("CHANNEL STATUS $channel");
        $ret['data'] = match ($ret['result']) {
            -1 => trim("There is no channel that matches $channel"),
            Constants::AST_STATE_DOWN => 'Channel is down and available',
            Constants::AST_STATE_RESERVED => 'Channel is down, but reserved',
            Constants::AST_STATE_OFFHOOK => 'Channel is off hook',
            Constants::AST_STATE_DIALING => 'Digits (or equivalent) have been dialed',
            Constants::AST_STATE_RING => 'Line is ringing',
            Constants::AST_STATE_RINGING => 'Remote end is ringing',
            Constants::AST_STATE_UP => 'Line is up',
            Constants::AST_STATE_BUSY => 'Line is busy',
            Constants::AST_STATE_DIALING_OFFHOOK => 'Digits (or equivalent) have been dialed while offhook',
            Constants::AST_STATE_PRERING => 'Channel has detected an incoming call and is waiting for ring',
            default => "Unknown ({$ret['result']})",
        };

        return $ret;
    }

    /**
     * Deletes an entry in the Asterisk database for a given family and key.
     *
     * @link http://www.voip-info.org/wiki-database+del
     *
     * @param  string  $family
     * @param  string  $key
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
     */
    public function database_del($family, $key): array
    {
        return $this->evaluate("DATABASE DEL \"$family\" \"$key\"");
    }

    /**
     * Deletes a family or specific keytree within a family in the Asterisk database.
     *
     * @link http://www.voip-info.org/wiki-database+deltree
     *
     * @param  string  $family
     * @param  string  $keytree
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
     */
    public function database_deltree($family, $keytree = ''): array
    {
        $cmd = "DATABASE DELTREE \"$family\"";
        if ($keytree != '') {
            $cmd .= " \"$keytree\"";
        }

        return $this->evaluate($cmd);
    }

    /**
     * Retrieves an entry in the Asterisk database for a given family and key.
     *
     * @link http://www.voip-info.org/wiki-database+get
     *
     * @param  string  $family
     * @param  string  $key
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 failure. ['data'] holds the value
     */
    public function database_get($family, $key): array
    {
        return $this->evaluate("DATABASE GET \"$family\" \"$key\"");
    }

    /**
     * Adds or updates an entry in the Asterisk database for a given family, key, and value.
     *
     * @param  string  $family
     * @param  string  $key
     * @param  string  $value
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function database_put($family, $key, $value): array
    {
        $value = str_replace("\n", '\n', addslashes($value));

        return $this->evaluate("DATABASE PUT \"$family\" \"$key\" \"$value\"");
    }

    /**
     * Sets a global variable, using Asterisk 1.6 syntax.
     *
     * @link http://www.voip-info.org/wiki/view/Asterisk+cmd+Set
     *
     * @param  string  $pVariable
     * @param  string|int|float  $pValue
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function set_global_var($pVariable, $pValue): array
    {
        if (is_numeric($pValue)) {
            return $this->evaluate("Set({$pVariable}={$pValue},g);");
        }

        return $this->evaluate("Set({$pVariable}=\"{$pValue}\",g);");
    }

    /**
     * Sets a variable, using Asterisk 1.6 syntax.
     *
     * @link http://www.voip-info.org/wiki/view/Asterisk+cmd+Set
     *
     * @param  string  $pVariable
     * @param  string|int|float  $pValue
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function set_var($pVariable, $pValue): array
    {
        if (is_numeric($pValue)) {
            return $this->evaluate("Set({$pVariable}={$pValue});");
        }

        return $this->evaluate("Set({$pVariable}=\"{$pValue}\");");
    }

    /**
     * Executes the specified Asterisk application with given options.
     *
     * @link http://www.voip-info.org/wiki-exec
     * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
     *
     * @param  string  $application
     * @param  mixed  $options
     * @return array, see evaluate for return information. ['result'] is whatever the application returns, or -2 on failure to find application
     */
    public function exec($application, $options): array
    {
        if (is_array($options)) {
            $options = implode('|', $options);
        }

        return $this->evaluate("EXEC $application $options");
    }

    /**
     * Plays the given file and receives DTMF data.
     *
     * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
     * while STREAM FILE returns immediately after the first DTMF digit is detected.
     *
     * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
     *
     * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
     * of silence then the command ends.
     *
     * The user has the opportunity to press a key at any time during the message or the
     * post-message silence. If the user presses a key while the message is playing, the
     * message stops playing. When the first key is pressed a timer starts counting for
     * $timeout milliseconds. Every time the user presses another key the timer is restarted.
     * The command ends when the counter goes to zero or the maximum number of digits is entered,
     * whichever happens first.
     *
     * If you don't specify a time out then a default timeout of 2000 is used following a pressed
     * digit. If no digits are pressed then 6 seconds of silence follow the message.
     *
     * If you don't specify $max_digits then the user can enter as many digits as they want.
     *
     * Pressing the # key has the same effect as the timer running out: the command ends and
     * any previously keyed digits are returned. A side effect of this is that there is no
     * way to read a # key using this command.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @link http://www.voip-info.org/wiki-get+data
     *
     * @param  string  $filename  file to play. Do not include file extension.
     * @param  int  $timeout  milliseconds
     * @param  int  $max_digits
     * @return array, see evaluate for return information. ['result'] holds the digits and ['data'] holds the timeout if present.
     *
     * This differs from other commands with return DTMF as numbers representing ASCII characters.
     */
    public function get_data($filename, $timeout = null, $max_digits = null): array
    {
        return $this->evaluate(rtrim("GET DATA $filename $timeout $max_digits"));
    }

    /**
     * Fetch the value of a variable.
     *
     * Does not work with global variables. Does not work with some variables that are generated by modules.
     *
     * @link http://www.voip-info.org/wiki-get+variable
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     *
     * @param  string  $variable  name
     * @param  bool  $getvalue  return the value only
     * @return int[], see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has. ['data'] holds the value. returns value if $getvalue is TRUE
     */
    public function get_variable($variable, $getvalue = false): array|int
    {
        $res = $this->evaluate("GET VARIABLE $variable");

        if ($getvalue == false) {
            return $res;
        }

        return $res['data'];
    }

    /**
     * Fetch the value of a full variable.
     *
     *
     * @link http://www.voip-info.org/wiki/view/get+full+variable
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     *
     * @param  string  $variable  name
     * @param  string  $channel  channel
     * @param  bool  $getvalue  return the value only
     * @return array, see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has. ['data'] holds the value.  returns value if $getvalue is TRUE
     */
    public function get_fullvariable(string $variable, $channel = false, $getvalue = false): array|int
    {
        $req = $channel == false ? $variable : $variable.' '.$channel;

        $res = $this->evaluate('GET FULL VARIABLE '.$req);

        if ($getvalue == false) {
            return $res;
        }

        return $res['data'];

    }

    /**
     * Hangup the specified channel. If no channel name is given, hang up the current channel.
     *
     * With power comes responsibility. Hanging up channels other than your own isn't something
     * that is done routinely. If you are not sure why you are doing so, then don't.
     *
     * @link http://www.voip-info.org/wiki-hangup
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     *
     * @param  string  $channel
     * @return array, see evaluate for return information. ['result'] is 1 on success, -1 on failure.
     */
    public function hangup($channel = ''): array
    {
        return $this->evaluate("HANGUP $channel");
    }

    /**
     * Does nothing.
     *
     * @link http://www.voip-info.org/wiki-noop
     *
     * @return array, see evaluate for return information.
     */
    public function noop($string = ''): array
    {
        return $this->evaluate("NOOP \"$string\"");
    }

    /**
     * Receive a character of text from a connected channel. Waits up to $timeout milliseconds for
     * a character to arrive, or infinitely if $timeout is zero.
     *
     * @link http://www.voip-info.org/wiki-receive+char
     *
     * @param  int  $timeout  milliseconds
     * @return array, see evaluate for return information. ['result'] is 0 on timeout or not supported, -1 on failure. Otherwise
     * it is the decimal value of the DTMF tone. Use chr() to convert to ASCII.
     */
    public function receive_char($timeout = -1): array
    {
        return $this->evaluate("RECEIVE CHAR $timeout");
    }

    /**
     * Record sound to a file until an acceptable DTMF digit is received or a specified amount of
     * time has passed. Optionally the file BEEP is played before recording begins.
     *
     * @link http://www.voip-info.org/wiki-record+file
     *
     * @param  string  $file  to record, without extension, often created in /var/lib/asterisk/sounds
     * @param  string  $format  of the file. GSM and WAV are commonly used formats. MP3 is read-only and thus cannot be used.
     * @param  string  $escape_digits
     * @param  int  $timeout  is the maximum record time in milliseconds, or -1 for no timeout.
     * @param  int  $offset  to seek to without exceeding the end of the file.
     * @param  bool  $beep
     * @param  int  $silence  number of seconds of silence allowed before the function returns despite the
     *                        lack of dtmf digits or reaching timeout.
     * @return array, see evaluate for return information. ['result'] is -1 on error, 0 on hangup, otherwise a decimal value of the
     * DTMF tone. Use chr() to convert to ASCII.
     */
    public function record_file($file, $format, $escape_digits = '', $timeout = -1, $offset = null, $beep = false, $silence = null): array
    {
        $cmd = trim("RECORD FILE $file $format \"$escape_digits\" $timeout $offset");
        if ($beep) {
            $cmd .= ' BEEP';
        }
        if (! is_null($silence)) {
            $cmd .= " s=$silence";
        }

        return $this->evaluate($cmd);
    }

    /**
     * Say a given character string, returning early if any of the given DTMF digits are received on the channel.
     *
     * @link https://www.voip-info.org/say-alpha
     *
     * @param  string  $text
     * @param  string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function say_alpha($text, $escape_digits = ''): array
    {
        return $this->evaluate("SAY ALPHA $text \"$escape_digits\"");
    }

    /**
     * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+digits
     *
     * @param  int  $digits
     * @param  string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function say_digits($digits, $escape_digits = ''): array
    {
        return $this->evaluate("SAY DIGITS $digits \"$escape_digits\"");
    }

    /**
     * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+number
     *
     * @param  int  $number
     * @param  string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function say_number($number, $escape_digits = ''): array
    {
        return $this->evaluate("SAY NUMBER $number \"$escape_digits\"");
    }

    /**
     * Say the given character string, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+phonetic
     *
     * @param  string  $text
     * @param  string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function say_phonetic($text, $escape_digits = ''): array
    {
        return $this->evaluate("SAY PHONETIC $text \"$escape_digits\"");
    }

    /**
     * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+time
     *
     * @param  int  $time  number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time (UTC).
     * @param  string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function say_time($time = null, $escape_digits = ''): array
    {
        if (is_null($time)) {
            $time = time();
        }

        return $this->evaluate("SAY TIME $time \"$escape_digits\"");
    }

    /**
     * Send the specified image on a channel.
     *
     * Most channels do not support the transmission of images.
     *
     * @link http://www.voip-info.org/wiki-send+image
     *
     * @param  string  $image  without extension, often in /var/lib/asterisk/images
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the image is sent or
     * channel does not support image transmission.
     */
    public function send_image($image): array
    {
        return $this->evaluate("SEND IMAGE $image");
    }

    /**
     * Send the given text to the connected channel.
     *
     * Most channels do not support transmission of text.
     *
     * @link http://www.voip-info.org/wiki-send+text
     *
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the text is sent or
     * channel does not support text transmission.
     */
    public function send_text($text): array
    {
        return $this->evaluate("SEND TEXT \"$text\"");
    }

    /**
     * Cause the channel to automatically hangup at $time seconds in the future.
     * If $time is 0 then the autohangup feature is disabled on this channel.
     *
     * If the channel is hungup prior to $time seconds, this setting has no effect.
     *
     * @link http://www.voip-info.org/wiki-set+autohangup
     *
     * @param  int  $time  until automatic hangup
     * @return array, see evaluate for return information.
     */
    public function set_autohangup($time = 0): array
    {
        return $this->evaluate("SET AUTOHANGUP $time");
    }

    /**
     * Changes the caller ID of the current channel.
     *
     * @link http://www.voip-info.org/wiki-set+callerid
     *
     * @param  string  $cid  example: "John Smith"<1234567>
     *                       This command will let you take liberties with the <caller ID specification> but the format shown in the example above works
     *                       well: the name enclosed in double quotes followed immediately by the number inside angle brackets. If there is no name then
     *                       you can omit it. If the name contains no spaces you can omit the double quotes around it. The number must follow the name
     *                       immediately; don't put a space between them. The angle brackets around the number are necessary; if you omit them the
     *                       number will be considered to be part of the name.
     * @return array, see evaluate for return information.
     */
    public function set_callerid($cid): array
    {
        return $this->evaluate("SET CALLERID $cid");
    }

    /**
     * Sets the context for continuation upon exiting the application.
     *
     * Setting the context does NOT automatically reset the extension and the priority; if you want to start at the top of the new
     * context you should set extension and priority yourself.
     *
     * If you specify a non-existent context you receive no error indication (['result'] is still 0) but you do get a
     * warning message on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+context
     *
     * @param  string  $context
     * @return array, see evaluate for return information.
     */
    public function set_context($context): array
    {
        return $this->evaluate("SET CONTEXT $context");
    }

    /**
     * Set the extension to be used for continuation upon exiting the application.
     *
     * Setting the extension does NOT automatically reset the priority. If you want to start with the first priority of the
     * extension you should set the priority yourself.
     *
     * If you specify a non-existent extension you receive no error indication (['result'] is still 0) but you do
     * get a warning message on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+extension
     *
     * @param  string  $extension
     * @return array, see evaluate for return information.
     */
    public function set_extension($extension): array
    {
        return $this->evaluate("SET EXTENSION $extension");
    }

    /**
     * Enable/Disable Music on hold generator.
     *
     * @link http://www.voip-info.org/wiki-set+music
     *
     * @param  bool  $enabled
     * @param  string  $class
     * @return array, see evaluate for return information.
     */
    public function set_music($enabled = true, $class = ''): array
    {
        $enabled = ($enabled) ? 'ON' : 'OFF';

        return $this->evaluate("SET MUSIC $enabled $class");
    }

    /**
     * Set the priority to be used for continuation upon exiting the application.
     *
     * If you specify a non-existent priority you receive no error indication (['result'] is still 0)
     * and no warning is issued on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+priority
     *
     * @param  int  $priority
     * @return array, see evaluate for return information.
     */
    public function set_priority($priority): array
    {
        return $this->evaluate("SET PRIORITY $priority");
    }

    /**
     * Sets a variable to the specified value. The variables so created can later be used by later using ${<variablename>}
     * in the dialplan.
     *
     * These variables live in the channel Asterisk creates when you pickup a phone and as such they are both local and temporary.
     * Variables created in one channel can not be accessed by another channel. When you hang up the phone, the channel is deleted
     * and any variables in that channel are deleted as well.
     *
     * @link http://www.voip-info.org/wiki-set+variable
     *
     * @param  string  $variable  is case sensitive
     * @param  string  $value
     * @return array, see evaluate for return information.
     */
    public function set_variable($variable, $value): array
    {
        $value = str_replace("\n", '\n', addslashes($value));

        return $this->evaluate("SET VARIABLE $variable \"$value\"");
    }

    /**
     * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the GET DATA
     * command but this command returns after the first DTMF digit has been pressed while GET DATA can accumulated any number of
     * digits before returning.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @link http://www.voip-info.org/wiki-stream+file
     *
     * @param  string  $filename  without extension, often in /var/lib/asterisk/sounds
     * @param  string  $escape_digits
     * @param  int  $offset
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function stream_file($filename, $escape_digits = '', $offset = 0): array
    {
        return $this->evaluate("STREAM FILE $filename \"$escape_digits\" $offset");
    }

    /**
     * Enable or disable TDD transmission/reception on the current channel.
     *
     * @link http://www.voip-info.org/wiki-tdd+mode
     *
     * @param  string  $setting  can be on, off or mate
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 if the channel is not TDD capable.
     */
    public function tdd_mode($setting): array
    {
        return $this->evaluate("TDD MODE $setting");
    }

    /**
     * Sends $message to the Asterisk console via the 'verbose' message system.
     *
     * If the Asterisk verbosity level is $level or greater, send $message to the console.
     *
     * The Asterisk verbosity system works as follows. The Asterisk user gets to set the desired verbosity at startup time or later
     * using the console 'set verbose' command. Messages are displayed on the console if their verbose level is less than or equal
     * to desired verbosity set by the user. More important messages should have a low verbose level; less important messages
     * should have a high verbose level.
     *
     * @link http://www.voip-info.org/wiki-verbose
     *
     * @param  string  $message
     * @param  int  $level  from 1 to 4
     * @return array, see evaluate for return information.
     */
    public function verbose($message, $level = 1)
    {
        foreach (explode("\n", str_replace("\r\n", "\n", print_r($message, true))) as $msg) {
            @syslog(LOG_WARNING, $msg);
            $ret = $this->evaluate("VERBOSE \"$msg\" $level");
        }

        return $ret;
    }

    /**
     * Waits up to $timeout milliseconds for channel to receive a DTMF digit.
     *
     * @link http://www.voip-info.org/wiki-wait+for+digit
     *
     * @param  int  $timeout  in millisecons. Use -1 for the timeout value if you want the call to wait indefinitely.
     * @return array, see evaluate for return information. ['result'] is 0 if wait completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function wait_for_digit($timeout = -1): array
    {
        return $this->evaluate("WAIT FOR DIGIT $timeout");
    }

    // *********************************************************************************************************
    // **                             APPLICATIONS                                                                                        **
    // *********************************************************************************************************

    /**
     * Set absolute maximum time of call.
     *
     * Note that the timeout is set from the current time forward, not counting the number of seconds the call has already been up.
     * Each time you call AbsoluteTimeout(), all previous absolute timeouts are cancelled.
     * Will return the call to the T extension so that you can playback an explanatory note to the calling party (the called party
     * will not hear that)
     *
     * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
     * @link http://www.dynx.net/ASTERISK/AGI/ccard/agi-ccard.agi
     *
     * @param  int  $seconds  allowed, 0 disables timeout
     * @return array, see evaluate for return information.
     */
    public function exec_absolutetimeout(int $seconds = 0): array
    {
        return $this->exec('AbsoluteTimeout', $seconds);
    }

    /**
     * Executes an AGI compliant application.
     *
     * @param  string  $command
     * @param  string  $args
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or if application requested hangup, or 0 on non-hangup exit.
     */
    public function exec_agi($command, $args): array
    {
        return $this->exec("AGI $command", $args);
    }

    /**
     * Set Language.
     *
     * @param  string  $language  code
     * @return array, see evaluate for return information.
     */
    public function exec_setlanguage(string $language = 'en'): array
    {
        return $this->exec('Set', 'CHANNEL(language)='.$language);
    }

    /**
     * Do ENUM Lookup.
     *
     * Note: to retrieve the result, use
     *   get_variable('ENUM');
     *
     * @return array, see evaluate for return information.
     */
    public function exec_enumlookup($exten): array
    {
        return $this->exec('EnumLookup', $exten);
    }

    /**
     * Dial.
     *
     * Dial takes input from ${VXML_URL} to send XML Url to Cisco 7960
     * Dial takes input from ${ALERT_INFO} to set ring cadence for Cisco phones
     * Dial returns ${CAUSECODE}: If the dial failed, this is the errormessage.
     * Dial returns ${DIALSTATUS}: Text code returning status of last dial attempt.
     *
     * @link http://www.voip-info.org/wiki-Asterisk+cmd+Dial
     *
     * @param  string  $type
     * @param  string  $identifier
     * @param  int  $timeout
     * @param  string  $options
     * @param  string  $url
     * @return array, see evaluate for return information.
     */
    public function exec_dial($type, $identifier, $timeout = null, $options = null, $url = null): array
    {
        return $this->exec('Dial', trim("$type/$identifier".$this->option_delim.$timeout.$this->option_delim.$options.$this->option_delim.$url, $this->option_delim));
    }

    /**
     * Goto.
     *
     * This function takes three arguments: context,extension, and priority, but the leading arguments
     * are optional, not the trailing arguments.  Thuse goto($z) sets the priority to $z.
     *
     * @param  string  $b;
     * @param  string  $c;
     * @return array, see evaluate for return information.
     */
    public function exec_goto(string $a, $b = null, $c = null): array
    {
        return $this->exec('Goto', trim($a.$this->option_delim.$b.$this->option_delim.$c, $this->option_delim));
    }

    // *********************************************************************************************************
    // **                             FAST PASSING                                                                                        **
    // *********************************************************************************************************
    /**
     * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.voip-info.org/wiki-say+digits
     *
     * @param  int  $digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function fastpass_say_digits(string &$buffer, $digits, ?string $escape_digits = ''): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->say_digits($digits, $escape_digits);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1])];
    }

    /**
     * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.voip-info.org/wiki-say+number
     *
     * @param  int  $number
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function fastpass_say_number(string &$buffer, $number, ?string $escape_digits = ''): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->say_number($number, $escape_digits);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1])];
    }

    /**
     * Say the given character string, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.voip-info.org/wiki-say+phonetic
     *
     * @param  string  $text
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function fastpass_say_phonetic(string &$buffer, $text, ?string $escape_digits = ''): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->say_phonetic($text, $escape_digits);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1])];
    }

    /**
     * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.voip-info.org/wiki-say+time
     *
     * @param  int  $time  number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time (UTC).
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function fastpass_say_time(string &$buffer, $time = null, ?string $escape_digits = ''): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->say_time($time, $escape_digits);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1])];
    }

    /**
     * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the GET DATA
     * command but this command returns after the first DTMF digit has been pressed while GET DATA can accumulated any number of
     * digits before returning.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.voip-info.org/wiki-stream+file
     *
     * @param  string  $filename  without extension, often in /var/lib/asterisk/sounds
     * @param  int  $offset
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
     */
    public function fastpass_stream_file(string &$buffer, $filename, ?string $escape_digits = '', $offset = 0): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->stream_file($filename, $escape_digits, $offset);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1]), 'endpos' => 0];
    }

    /**
     * Use festival to read text.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.cstr.ed.ac.uk/projects/festival/
     *
     * @param  string  $text
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     */
    public function fastpass_text2wav(string &$buffer, $text, ?string $escape_digits = '', $frequency = 8000): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->text2wav($text, $escape_digits, $frequency);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1]), 'endpos' => 0];
    }

    /**
     * Use Cepstral Swift to read text.
     * Return early if $buffer is adequate for request.
     *
     * @link http://www.cepstral.com/
     *
     * @param  string  $text
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     */
    public function fastpass_swift(string &$buffer, $text, ?string $escape_digits = '', $frequency = 8000, $voice = null): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->swift($text, $escape_digits, $frequency, $voice);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1]), 'endpos' => 0];
    }

    /**
     * Say Puncutation in a string.
     * Return early if $buffer is adequate for request.
     *
     * @param  string  $text
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     */
    public function fastpass_say_punctuation(string &$buffer, $text, ?string $escape_digits = '', $frequency = 8000): array
    {
        $proceed = false;
        if ($escape_digits != '' && $buffer !== '' && ! strpos(chr(255).$escape_digits, $buffer[strlen($buffer) - 1])) {
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->say_punctuation($text, $escape_digits, $frequency);
            if ($res['code'] == Constants::AGIRES_OK && $res['result'] > 0) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }

        return ['code' => Constants::AGIRES_OK, 'result' => ord($buffer[strlen($buffer) - 1])];
    }

    /**
     * Plays the given file and receives DTMF data.
     * Return early if $buffer is adequate for request.
     *
     * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
     * while STREAM FILE returns immediately after the first DTMF digit is detected.
     *
     * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
     *
     * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
     * of silence then the command ends.
     *
     * The user has the opportunity to press a key at any time during the message or the
     * post-message silence. If the user presses a key while the message is playing, the
     * message stops playing. When the first key is pressed a timer starts counting for
     * $timeout milliseconds. Every time the user presses another key the timer is restarted.
     * The command ends when the counter goes to zero or the maximum number of digits is entered,
     * whichever happens first.
     *
     * If you don't specify a time out then a default timeout of 2000 is used following a pressed
     * digit. If no digits are pressed then 6 seconds of silence follow the message.
     *
     * If you don't specify $max_digits then the user can enter as many digits as they want.
     *
     * Pressing the # key has the same effect as the timer running out: the command ends and
     * any previously keyed digits are returned. A side effect of this is that there is no
     * way to read a # key using this command.
     *
     * @link http://www.voip-info.org/wiki-get+data
     *
     * @param  string  $filename  file to play. Do not include file extension.
     * @param  int  $timeout  milliseconds
     * @param  int  $max_digits
     * @return array, see evaluate for return information. ['result'] holds the digits and ['data'] holds the timeout if present.
     *
     * This differs from other commands with return DTMF as numbers representing ASCII characters.
     */
    public function fastpass_get_data(string &$buffer, $filename, $timeout = null, $max_digits = null): array
    {
        if (is_null($max_digits) || strlen($buffer) < $max_digits) {
            if ($buffer === '') {
                $res = $this->get_data($filename, $timeout, $max_digits);
                if ($res['code'] == Constants::AGIRES_OK) {
                    $buffer .= $res['result'];
                }

                return $res;
            }
            while (is_null($max_digits) || strlen($buffer) < $max_digits) {
                $res = $this->wait_for_digit();
                if ($res['code'] != Constants::AGIRES_OK) {
                    return $res;
                }
                if ($res['result'] == ord('#')) {
                    break;
                }
                $buffer .= chr($res['result']);
            }
        }

        return ['code' => Constants::AGIRES_OK, 'result' => $buffer];
    }

    // *********************************************************************************************************
    // **                             DERIVED                                                                                             **
    // *********************************************************************************************************

    /**
     * Menu.
     *
     * This function presents the user with a menu and reads the response
     *
     * @param  array  $choices  has the following structure:
     *                          array('1'=>'*Press 1 for this', // festival reads if prompt starts with *
     *                          '2'=>'some-gsm-without-extension',
     *                          '*'=>'*Press star for help');
     * @return mixed key pressed on sucess, -1 on failure
     */
    public function menu($choices, $timeout = 2000)
    {
        $keys = implode('', array_keys($choices));
        $choice = null;
        while (is_null($choice)) {
            foreach ($choices as $prompt) {
                $ret = $prompt[0] == '*' ? $this->text2wav(substr((string) $prompt, 1), $keys) : $this->stream_file($prompt, $keys);

                if ($ret['code'] != Constants::AGIRES_OK || $ret['result'] == -1) {
                    $choice = -1;
                    break;
                }

                if ($ret['result'] != 0) {
                    $choice = chr($ret['result']);
                    break;
                }
            }

            if (is_null($choice)) {
                $ret = $this->get_data('beep', $timeout, 1);
                if ($ret['code'] != Constants::AGIRES_OK || $ret['result'] == -1) {
                    $choice = -1;
                } elseif ($ret['result'] != '' && strpos(' '.$keys, (string) $ret['result'])) {
                    $choice = $ret['result'];
                }
            }
        }

        return $choice;
    }

    /**
     * setContext - Set context, extension and priority.
     *
     * @param  string  $context
     * @param  string  $extension
     * @param  string  $priority
     */
    public function setContext($context, $extension = 's', $priority = 1): void
    {
        $this->set_context($context);
        $this->set_extension($extension);
        $this->set_priority($priority);
    }

    /**
     * Parse caller id.
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     *
     * "name" <proto:user@server:port>
     *
     * @param  string  $callerid
     * @return array('Name'=>$name, 'Number'=>$number)
     */
    public function parse_callerid($callerid = null): array
    {
        if (is_null($callerid)) {
            $callerid = $this->request['agi_callerid'];
        }

        $ret = ['name' => '', 'protocol' => '', 'port' => ''];
        $callerid = trim((string) $callerid);

        if ($callerid[0] == '"' || $callerid[0] == "'") {
            $d = $callerid[0];
            $callerid = explode($d, substr($callerid, 1));
            $ret['name'] = array_shift($callerid);
            $callerid = implode($d, $callerid);
        }

        $callerid = explode('@', trim($callerid, '<> '));
        $username = explode(':', array_shift($callerid));
        if (count($username) === 1) {
            $ret['username'] = $username[0];
        } else {
            $ret['protocol'] = array_shift($username);
            $ret['username'] = implode(':', $username);
        }

        $callerid = implode('@', $callerid);
        $host = explode(':', $callerid);
        if (count($host) === 1) {
            $ret['host'] = $host[0];
        } else {
            $ret['host'] = array_shift($host);
            $ret['port'] = implode(':', $host);
        }

        return $ret;
    }

    /**
     * Use festival to read text.
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     *
     * @link http://www.cstr.ed.ac.uk/projects/festival/
     *
     * @param  string  $text
     * @param  string  $escape_digits
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     * @return mixed[]
     */
    public function text2wav($text, $escape_digits = '', $frequency = 8000): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['code' => Constants::AGIRES_OK, 'result' => 0, 'data' => ''];
        }

        $hash = md5($text);
        $fname = $this->config['phpagi']['tempdir'].DIRECTORY_SEPARATOR;
        $fname .= 'text2wav_'.$hash;

        // create wave file
        if (! file_exists("$fname.wav")) {
            // write text file
            if (! file_exists("$fname.txt")) {
                $fp = fopen("$fname.txt", 'w');
                fwrite($fp, $text);
                fclose($fp);
            }

            shell_exec("{$this->config['festival']['text2wave']} -F $frequency -o $fname.wav $fname.txt");
        } else {
            touch("$fname.txt");
            touch("$fname.wav");
        }

        // stream it
        $ret = $this->stream_file($fname, $escape_digits);

        // clean up old files
        $delete = time() - 2592000; // 1 month
        foreach (glob($this->config['phpagi']['tempdir'].DIRECTORY_SEPARATOR.'text2wav_*') as $file) {
            if (filemtime($file) < $delete) {
                unlink($file);
            }
        }

        return $ret;
    }

    /**
     * Use Cepstral Swift to read text.
     *
     * @link http://www.cepstral.com/
     *
     * @param  string  $text
     * @param  string  $escape_digits
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     * @return mixed[]
     */
    public function swift($text, $escape_digits = '', $frequency = 8000, $voice = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['code' => Constants::AGIRES_OK, 'result' => 0, 'data' => ''];
        }

        $hash = md5($text);
        $fname = $this->config['phpagi']['tempdir'].DIRECTORY_SEPARATOR;
        $fname .= 'swift_'.$hash;

        // create wave file
        if (! file_exists("$fname.wav")) {
            // write text file
            if (! file_exists("$fname.txt")) {
                $fp = fopen("$fname.txt", 'w');
                fwrite($fp, $text);
                fclose($fp);
            }

            shell_exec("{$this->config['cepstral']['swift']} -p audio/channels=1,audio/sampling-rate=$frequency $voice -o $fname.wav -f $fname.txt");
        }

        // stream it
        $ret = $this->stream_file($fname, $escape_digits);

        // clean up old files
        $delete = time() - 2592000; // 1 month
        foreach (glob($this->config['phpagi']['tempdir'].DIRECTORY_SEPARATOR.'swift_*') as $file) {
            if (filemtime($file) < $delete) {
                unlink($file);
            }
        }

        return $ret;
    }

    /**
     * Text Input.
     *
     * Based on ideas found at http://www.voip-info.org/wiki-Asterisk+cmd+DTMFToText
     *
     * Example:
     *                  UC   H     LC   i        ,     SP   h     o        w    SP   a    r        e     SP   y        o        u     ?
     *   $string = '*8'.'44*'.'*5'.'444*'.'00*'.'0*'.'44*'.'666*'.'9*'.'0*'.'2*'.'777*'.'33*'.'0*'.'999*'.'666*'.'88*'.'0000*';
     *
     * @link http://www.voip-info.org/wiki-Asterisk+cmd+DTMFToText
     *
     * @example examples/input.php Get text input from the user and say it back
     */
    public function text_input($mode = 'NUMERIC'): string
    {
        $alpha = ['k0' => ' ', 'k00' => ',', 'k000' => '.', 'k0000' => '?', 'k00000' => '0',
            'k1' => '!', 'k11' => ':', 'k111' => ';', 'k1111' => '#', 'k11111' => '1',
            'k2' => 'A', 'k22' => 'B', 'k222' => 'C', 'k2222' => '2',
            'k3' => 'D', 'k33' => 'E', 'k333' => 'F', 'k3333' => '3',
            'k4' => 'G', 'k44' => 'H', 'k444' => 'I', 'k4444' => '4',
            'k5' => 'J', 'k55' => 'K', 'k555' => 'L', 'k5555' => '5',
            'k6' => 'M', 'k66' => 'N', 'k666' => 'O', 'k6666' => '6',
            'k7' => 'P', 'k77' => 'Q', 'k777' => 'R', 'k7777' => 'S', 'k77777' => '7',
            'k8' => 'T', 'k88' => 'U', 'k888' => 'V', 'k8888' => '8',
            'k9' => 'W', 'k99' => 'X', 'k999' => 'Y', 'k9999' => 'Z', 'k99999' => '9'];
        $symbol = [
            'k0' => '=',
            'k1' => '<', 'k11' => '(', 'k111' => '[', 'k1111' => '{', 'k11111' => '1',
            'k2' => '@', 'k22' => '$', 'k222' => '&', 'k2222' => '%', 'k22222' => '2',
            'k3' => '>', 'k33' => ')', 'k333' => ']', 'k3333' => '}', 'k33333' => '3',
            'k4' => '+', 'k44' => '-', 'k444' => '*', 'k4444' => '/', 'k44444' => '4',
            'k5' => "'", 'k55' => '`', 'k555' => '5',
            'k6' => '"', 'k66' => '6',
            'k7' => '^', 'k77' => '7',
            'k8' => '\\', 'k88' => '|', 'k888' => '8',
            'k9' => '_', 'k99' => '~', 'k999' => '9',
        ];
        $text = '';
        do {
            $command = false;
            $result = $this->get_data('beep');
            foreach (explode('*', (string) $result['result']) as $code) {
                if ($command) {
                    switch ($code[0]) {
                        case '2': $text = substr($text, 0, strlen($text) - 1);
                            break; // backspace
                        case '5': $mode = 'LOWERCASE';
                            break;
                        case '6': $mode = 'NUMERIC';
                            break;
                        case '7': $mode = 'SYMBOL';
                            break;
                        case '8': $mode = 'UPPERCASE';
                            break;
                        case '9': $text = explode(' ', $text);
                            unset($text[count($text) - 1]);
                            $text = implode(' ', $text);
                            break; // backspace a word
                    }
                    $code = substr($code, 1);
                    $command = false;
                }
                if ($code === '') {
                    $command = true;
                } elseif ($mode == 'NUMERIC') {
                    $text .= $code;
                } elseif ($mode == 'UPPERCASE' && isset($alpha['k'.$code])) {
                    $text .= $alpha['k'.$code];
                } elseif ($mode == 'LOWERCASE' && isset($alpha['k'.$code])) {
                    $text .= strtolower($alpha['k'.$code]);
                } elseif ($mode == 'SYMBOL' && isset($symbol['k'.$code])) {
                    $text .= $symbol['k'.$code];
                }
            }
            $this->say_punctuation($text);
        } while (str_ends_with((string) $result['result'], '**'));

        return $text;
    }

    /**
     * Say Puncutation in a string.
     *
     * @param  string  $text
     * @param  string  $escape_digits
     * @param  int  $frequency
     * @return array, see evaluate for return information.
     */
    public function say_punctuation($text, $escape_digits = '', $frequency = 8000): array
    {
        $ret = '';
        for ($i = 0; $i < strlen($text); $i++) {
            switch ($text[$i]) {
                case ' ': $ret .= 'SPACE ';
                case ',': $ret .= 'COMMA ';
                    break;
                case '.': $ret .= 'PERIOD ';
                    break;
                case '?': $ret .= 'QUESTION MARK ';
                    break;
                case '!': $ret .= 'EXPLANATION POINT ';
                    break;
                case ':': $ret .= 'COLON ';
                    break;
                case ';': $ret .= 'SEMICOLON ';
                    break;
                case '#': $ret .= 'POUND ';
                    break;
                case '=': $ret .= 'EQUALS ';
                    break;
                case '<': $ret .= 'LESS THAN ';
                    break;
                case '(': $ret .= 'LEFT PARENTHESIS ';
                    break;
                case '[': $ret .= 'LEFT BRACKET ';
                    break;
                case '{': $ret .= 'LEFT BRACE ';
                    break;
                case '@': $ret .= 'AT ';
                    break;
                case '$': $ret .= 'DOLLAR SIGN ';
                    break;
                case '&': $ret .= 'AMPERSAND ';
                    break;
                case '%': $ret .= 'PERCENT ';
                    break;
                case '>': $ret .= 'GREATER THAN ';
                    break;
                case ')': $ret .= 'RIGHT PARENTHESIS ';
                    break;
                case ']': $ret .= 'RIGHT BRACKET ';
                    break;
                case '}': $ret .= 'RIGHT BRACE ';
                    break;
                case '+': $ret .= 'PLUS ';
                    break;
                case '-': $ret .= 'MINUS ';
                    break;
                case '*': $ret .= 'ASTERISK ';
                    break;
                case '/': $ret .= 'SLASH ';
                    break;
                case "'": $ret .= 'SINGLE QUOTE ';
                    break;
                case '`': $ret .= 'BACK TICK ';
                    break;
                case '"': $ret .= 'QUOTE ';
                    break;
                case '^': $ret .= 'CAROT ';
                    break;
                case '\\': $ret .= 'BACK SLASH ';
                    break;
                case '|': $ret .= 'BAR ';
                    break;
                case '_': $ret .= 'UNDERSCORE ';
                    break;
                case '~': $ret .= 'TILDE ';
                    break;
                default: $ret .= $text[$i].' ';
                    break;
            }
        }

        return $this->text2wav($ret, $escape_digits, $frequency);
    }

    /**
     * Create a new AgiAsteriskManager.
     */
    public function new_AsteriskManager(): AgiAsteriskManager
    {
        $this->asmanager = new AgiAsteriskManager(null, $this->config['asmanager'] ?? []);
        $this->asmanager->setPagi($this);
        // keep config slot updated
        $this->config['asmanager'] = $this->asmanager->config['asmanager'];

        return $this->asmanager;
    }

    // *********************************************************************************************************
    // **                             PRIVATE                                                                                             **
    // *********************************************************************************************************

    /**
     * Evaluate an AGI command.
     *
     * @param  string  $command
     * @return array ('code'=>$code, 'result'=>$result, 'data'=>$data)
     */
    public function evaluate($command): array
    {
        $broken = ['code' => 500, 'result' => -1, 'data' => ''];

        // write command
        if (! @fwrite($this->out, trim($command)."\n")) {
            return $broken;
        }
        fflush($this->out);

        // Read result.  Occasionally, a command return a string followed by an extra new line.
        // When this happens, our script will ignore the new line, but it will still in the
        // buffer.  So, if we get a blank line, it is probably the result of a previous
        // command.  We read until we get a valid result or asterisk hangs up.  One offending
        // command is SEND TEXT.
        $count = 0;
        do {
            $str = trim(fgets($this->in, 4096));
        } while ($str === '' && $count++ < 5);

        if ($count >= 5) {
            //          $this->conlog("evaluate error on read for $command");
            return $broken;
        }

        // parse result
        $ret['code'] = substr($str, 0, 3);
        $str = trim(substr($str, 3));

        if ($str[0] == '-') { // we have a multiline response!
            $count = 0;
            $str = substr($str, 1)."\n";
            $line = fgets($this->in, 4096);
            while (substr($line, 0, 3) != $ret['code'] && $count < 5) {
                $str .= $line;
                $line = fgets($this->in, 4096);
                $count = (trim($line) === '') ? $count + 1 : 0;
            }
            if ($count >= 5) {
                //            $this->conlog("evaluate error on multiline read for $command");
                return $broken;
            }
        }

        $ret['result'] = null;
        $ret['data'] = '';
        if ($ret['code'] != Constants::AGIRES_OK) { // some sort of error
            $ret['data'] = $str;
            $this->conlog(print_r($ret, true));
        } else { // normal AGIRES_OK response
            $parse = explode(' ', trim($str));
            $in_token = false;
            foreach ($parse as $token) {
                if ($in_token) { // we previously hit a token starting with ')' but not ending in ')'
                    $ret['data'] .= ' '.trim($token, '() ');
                    if ($token[strlen($token) - 1] == ')') {
                        $in_token = false;
                    }
                } elseif ($token[0] == '(') {
                    if ($token[strlen($token) - 1] != ')') {
                        $in_token = true;
                    }
                    $ret['data'] .= ' '.trim($token, '() ');
                } elseif (strpos($token, '=')) {
                    $token = explode('=', $token);
                    $ret[$token[0]] = $token[1];
                } elseif ($token !== '') {
                    $ret['data'] .= ' '.$token;
                }
            }
            $ret['data'] = trim((string) $ret['data']);
        }

        // log some errors
        if ($ret['result'] < 0) {
            $this->conlog("$command returned {$ret['result']}");
        }

        return $ret;
    }

    /**
     * Log to console if debug mode.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @param  string  $str
     * @param  int  $vbl  verbose level
     */
    public function conlog($str, $vbl = 1): void
    {
        static $busy = false;

        if ($this->config['phpagi']['debug'] != false && ! $busy) {
            // no conlogs inside conlog!!!
            $busy = true;
            $this->verbose($str, $vbl);
            $busy = false;
        }
    }

    /**
     * Find an execuable in the path.
     *
     * @param  string  $cmd  command to find
     * @param  string  $checkpath  path to check
     * @return string the path to the command
     */
    public function which($cmd, $checkpath = null): string|false
    {
        if (is_null($checkpath)) {
            $chpath = getenv('PATH');
            if ($chpath === false) {
                $chpath = '/bin:/sbin:/usr/bin:/usr/sbin:/usr/local/bin:/usr/local/sbin:'.
                    '/usr/X11R6/bin:/usr/local/apache/bin:/usr/local/mysql/bin';
            }
        } else {
            $chpath = $checkpath;
        }

        foreach (explode(':', $chpath) as $path) {
            if (is_executable("$path/$cmd")) {
                return "$path/$cmd";
            }
        }

        return false;
    }

    /**
     * Make a folder recursively.
     *
     * @param  string  $folder
     * @param  int  $perms
     */
    public function make_folder($folder, $perms = 0755): bool
    {
        $f = explode(DIRECTORY_SEPARATOR, $folder);
        $base = '';
        $counter = count($f);
        for ($i = 0; $i < $counter; $i++) {
            $base .= $f[$i];
            if ($f[$i] != '' && ! file_exists($base) && mkdir($base, $perms) === false) {
                return false;
            }
            $base .= DIRECTORY_SEPARATOR;
        }

        return true;
    }
}

/**
 * error handler for phpagi.
 *
 * @param  int  $level  PHP error level
 * @param  string  $message  error message
 * @param  string  $file  path to file
 * @param  int  $line  line number of error
 * @param  array|null  $context  variables in the current scope (deprecated in modern PHP)
 */
function phpagi_error_handler(int $level, string $message, string $file, int $line, ?array $context = null): void
{
    if (ini_get('error_reporting') == 0) {
        return;
    } // this happens with an @

    @syslog(LOG_WARNING, $file.'['.$line.']: '.$message);

    global $phpagi_error_handler_email;
    if (function_exists('mail') && ! is_null($phpagi_error_handler_email)) { // generate email debugging information
        // decode error level
        $levelName = match ($level) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_USER_ERROR => 'Error',
            default => (string) $level,
        };

        // build message
        $basefile = basename($file);
        $subject = "$basefile/$line/$levelName: $message";
        $messageBody = "$levelName: $message in $file on line $line\n\n";

        // legacy mysql_* functions removed in modern PHP; prefer mysqli if available
        if (strpos(' '.strtolower($messageBody), 'mysql') && function_exists('mysqli_errno')) {
            // We can't access a mysqli link here, so include a generic note
            $messageBody .= 'MySQL detected in message, mysqli functions available.'."\n\n";
        }

        // figure out who we are
        if (function_exists('socket_create')) {
            $addr = null;
            $port = 80;
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            @socket_connect($socket, '64.0.0.0', $port);
            @socket_getsockname($socket, $addr, $port);
            @socket_close($socket);
            $messageBody .= "\n\nIP Address: $addr\n";
        }

        // include variables
        $messageBody .= "\n\nContext:\n".print_r($context, true);
        $messageBody .= "\n\nGLOBALS:\n".print_r($GLOBALS, true);
        $messageBody .= "\n\nBacktrace:\n".print_r(debug_backtrace(), true);

        // include code fragment
        if (file_exists($file)) {
            $messageBody .= "\n\n$file:\n";
            $code = @file($file);
            for ($i = max(0, $line - 10); $i < min($line + 10, count($code)); $i++) {
                $messageBody .= ($i + 1)."\t$code[$i]";
            }
        }

        // make sure message is fully readable (convert unprintable chars to hex representation)
        $ret = '';
        for ($i = 0; $i < strlen($messageBody); $i++) {
            $c = ord($messageBody[$i]);
            if (in_array($c, [10, 13, 9])) {
                $ret .= $messageBody[$i];
            } elseif ($c < 16) {
                $ret .= '\\x0'.dechex($c);
            } elseif ($c < 32 || $c > 127) {
                $ret .= '\\x'.dechex($c);
            } else {
                $ret .= $messageBody[$i];
            }
        }

        // send the mail if less than 5 errors
        static $mailcount = 0;
        if ($mailcount < 5) {
            @mail($phpagi_error_handler_email, $subject, $ret);
        }
        $mailcount++;
    }
}
