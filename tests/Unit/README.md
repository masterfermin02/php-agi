# FastAGI Test Suite

## Overview

Comprehensive test coverage for the FastAGI server and FastAgiRequest classes.

## Test Files

### 1. Unit Tests (`tests/Unit/FastAgiTest.php`)

#### FastAgi Server Tests
- ✅ Can be instantiated with default parameters
- ✅ Can be instantiated with custom host and port
- ✅ Can be instantiated with options (debug, scriptDir, backlog)
- ✅ Can set a custom request handler
- ✅ Can set script directory
- ✅ Can enable/disable debug mode
- ✅ Throws exception when failing to create server socket on invalid host
- ✅ Throws exception when failing to bind to privileged port without permissions
- ✅ Can shutdown gracefully

#### FastAgiRequest Tests
- ✅ Can be instantiated with a socket
- ✅ Can read AGI environment from socket
- ✅ Returns false when failing to read environment
- ✅ Can get specific environment variable with default value
- ✅ Can get all environment variables
- ✅ Handles AGI response parsing correctly (requires pcntl extension)

#### FastAgiRequest AGI Commands Tests
- ✅ Inherits from Agi class
- ✅ Has access to answer method
- ✅ Has access to hangup method
- ✅ Has access to stream_file method
- ✅ Has access to get_data method
- ✅ Has access to text2wav method

#### FastAgi Integration Tests
- ✅ Can start server and accept connection on random port (requires pcntl extension)

#### FastAgiRequest Response Parsing Tests
- ✅ Parses successful AGI response (200 result=0)
- ✅ Parses AGI response with data (200 result=1 (timeout))
- ✅ Parses AGI error response (510 result=-1)

**Total Unit Tests: 23**

### 2. Feature Tests (`tests/Feature/FastAgiTest.php`)

#### FastAGI Server Feature Tests
- ✅ Handles request with custom handler
- ✅ Validates script directory security
- ✅ Can configure multiple options at once
- ✅ Allows chaining configuration methods

#### FastAGI Network Protocol Tests
- ✅ Properly formats AGI commands for network transmission
- ✅ Handles environment with special characters
- ✅ Handles empty environment gracefully

#### FastAGI Error Handling Tests
- ✅ Handles socket closure gracefully
- ✅ Returns error on invalid response format

#### FastAGI Script Execution Tests
- ✅ Prevents directory traversal attacks
- ✅ Validates script file exists

#### FastAGI Debugging and Logging Tests
- ✅ Enables debug mode via constructor
- ✅ Enables debug mode via setter
- ✅ Disables debug mode

**Total Feature Tests: 14**

## Running the Tests

### Run all FastAGI tests:
```bash
./vendor/bin/pest tests/Unit/FastAgiTest.php tests/Feature/FastAgiTest.php
```

### Run only unit tests:
```bash
./vendor/bin/pest tests/Unit/FastAgiTest.php
```

### Run only feature tests:
```bash
./vendor/bin/pest tests/Feature/FastAgiTest.php
```

### Run with verbose output:
```bash
./vendor/bin/pest tests/Unit/FastAgiTest.php -v
```

### Run specific test:
```bash
./vendor/bin/pest --filter="can be instantiated with default parameters"
```

## Test Coverage

### What's Tested

1. **Server Initialization**
   - Default and custom configuration
   - Host and port binding
   - Options handling (debug, scriptDir, backlog)

2. **Request Handling**
   - Socket-based communication
   - AGI environment variable parsing
   - Custom request handlers
   - Script-based execution mode

3. **Network Protocol**
   - AGI environment reading
   - Command formatting
   - Response parsing
   - Special character handling

4. **Security**
   - Directory traversal prevention
   - Script validation
   - Error handling for invalid inputs

5. **Error Handling**
   - Socket closure
   - Invalid responses
   - Missing scripts
   - Network errors

6. **Configuration**
   - Debug mode
   - Script directories
   - Handler callbacks
   - Server options

### What's NOT Tested (Integration Tests Required)

1. **Real Asterisk Integration**
   - Actual call handling
   - Real AGI command execution
   - Audio processing
   - Channel management

2. **Concurrent Connections**
   - Multiple simultaneous calls
   - Load testing
   - Connection pooling

3. **Long-Running Scenarios**
   - Memory leaks
   - Performance degradation
   - Resource cleanup

## Notes

- Some tests require the `pcntl` extension (process control) to test forking behavior
- Tests that require `pcntl` will be skipped if the extension is not available
- Socket pairs are used to simulate network communication without actual network calls
- Reflection is used to test private methods (parseAgiResponse, evaluate)

## Dependencies

- PHP 8.2+
- Pest testing framework
- ext-pcntl (optional, for fork-based tests)
- ext-posix (optional, for privilege tests)

## Example Test Execution Output

```
PASS  Tests\Unit\FastAgi
✓ it can be instantiated with default parameters
✓ it can be instantiated with custom host and port
✓ it can be instantiated with options
...

PASS  Tests\Feature\FastAgi
✓ it handles request with custom handler
✓ it validates script directory security
...

Tests:  37 passed
Time:   0.15s
```
