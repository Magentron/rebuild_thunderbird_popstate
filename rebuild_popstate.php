#!/usr/bin/php
<?php
	/**
	 *	Rebuild Thunderbird's popstate.dat file
	 *
	 *	@author 	Jeroen Derks
	 *	@since		2011/Oct/10
	 *	@copyright	(c) 2011-2013 Jeroen Derks
	 *	@license	http://www.apache.org/licenses/LICENSE-2.0
	 *
	 *	Licensed under the Apache License, Version 2.0 (the "License");
	 *	you may not use this file except in compliance with the License.
	 *	You may obtain a copy of the License at
	 *	
	 *	http://www.apache.org/licenses/LICENSE-2.0
	 *	
	 *	Unless required by applicable law or agreed to in writing, software
	 *	distributed under the License is distributed on an "AS IS" BASIS,
	 *	WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
	 *	See the License for the specific language governing permissions and
	 *	limitations under the License.
	 */
	define('PROG',      basename(IsSet($_SERVER['argv']) ? $_SERVER['argv'][0] : $_SERVER['SCRIPT_NAME']));
	define('VERSION',   '1.2');
	define('ISWIN',     'WIN' == strtoupper(substr(PHP_OS, 0, 3)));
	define('MSG_STATE', 'k');


	//////////////////////////////////////////////////////////////////////////////
	//	Defaults
	//

	$flag_crlf    = 0;                         // CRLF flag
	$flag_debug   = 0;                         // debug flag
	$flag_secure  = 0;                         // secure connection (SSL/TLS) flag
	$flag_verbose = 0;                         // verbose flag
	$output_file  = 'popstate.dat.GENERATED';  // output file
	$skip_count   = 0;                         // skip number of last messages


	//////////////////////////////////////////////////////////////////////////////
	//	Main
	//

	// header
	fputs(STDERR, PROG . '/' . VERSION . " Copyright (c) 2011-2013 Jeroen Derks, Apache License 2.0" . PHP_EOL);

	// process command line parameters
	$argc = $_SERVER['argc'];
	if ( 2 > $argc )
		usage();

	$argv = $_SERVER['argv'];
	while ( 1 < count($argv) )
	{
		$arg = $argv[1];
		if ( '-' == $arg[0] )
		{
			$length = strlen($arg);
			if (1 == $length)
				break;

			for ($i = 1; $i < $length; ++$i)
			{
				switch ( $arg[$i] )
				{
					case 'c':	++$flag_crlf;    break;
					case 'd':	++$flag_debug;   break;
					case 's':	++$flag_secure;  break;
					case 'v':	++$flag_verbose; break;

					case 'f':
						if ( 3 > count($argv) )
							usage('missing file after -f');
						array_shift($argv);
						$output_file = $argv[1];
						break;

					case 'i':
						if ( 3 > count($argv) )
							usage('missing file after -i');
						array_shift($argv);
						$skip_count = (integer) $argv[1];
						break;

					case 'h':
					case 'H':
					case '?':
						usage();

					default:	usage('unknown flag: -' . $arg[$i]);
				}
			}
			array_shift($argv);
		}
		else
			break;
		
	}

	if ( 1 < count($argv) )
		$server = $argv[1];
	else
		usage();

	if ( 2 < count($argv) )
		if ( is_numeric($argv[2]) )
			$port = $argv[2];
		else
			usage();
	else
		$port = $flag_secure ? 995 : 110;

	debug("flag_crlf    = " . $flag_crlf);
	debug("flag_debug   = " . $flag_debug);
	debug("flag_secure  = " . $flag_secure);
	debug("flag_verbose = " . $flag_verbose);
	debug("output_file  = " . $output_file);
	debug("skip_count   = " . $skip_count);
	debug("server       = " . $server);
	debug("port         = " . $port);

	// connect to POP3 server
	ini_set('trace_errors', true);
	$fp = fsockopen(($flag_secure ? 'ssl://' : '') . $server, $port);
	if ( false === $fp )
		error("failed to connect to $server:$port", 3);
	else
		skip($fp);
	verbose("connected to $server:$port" . ($flag_secure ? ' [using SSL]' : ''));

	// login
	$username = do_login($fp);

	// list
	//$list = do_list($fp);

	// get UIDLs
	$uidls = do_uidls($fp, $skip_count);

	// generate popstate.dat file
	generate($output_file, $uidls, $server, $username);

	// end connection
	verbose('disconnecting');
	write_line($fp, 'QUIT');
	fclose($fp);


	//////////////////////////////////////////////////////////////////////////////
	//	Functions
	//

	function do_login( $fp )
	{
		verbose('logging in');

		echo "username: "; $username = trim(fgets(STDIN, 1024));
		write_line($fp, 'USER ' . $username);
		skip($fp);

		echo "password: "; $password = trim(get_password());
		write_line($fp, 'PASS ' . $password);
		skip($fp);

		return $username;
	}

	function do_list( $fp )
	{
		verbose('retrieving list of messages');

		write_line($fp, 'LIST');
		skip($fp);

		$list = read_lines($fp, '.');
		foreach ( $list as $i => $line )
			$list[$i] = strtok($line, ' ');

		return $list;
	}

	function do_uidls( $fp, $skip_count )
	{
		global $flag_verbose, $flag_debug;
		verbose('retrieving list of UIDL\'s... ', $flag_debug);

		write_line($fp, 'UIDL');
		skip($fp);

		$uidls = read_lines($fp, '.', $flag_verbose);
		if ( $flag_verbose && !$flag_debug )
			echo PHP_EOL;

		verbose('parsing list of UIDL\'s');

		foreach ( $uidls as $i => $line )
		{
			strtok($line, ' ');
			$uidls[$i] = strtok('');
		}

		if ( 0 < $skip_count )
		{
			verbose('skipping last ' . $skip_count . ' messages');
			$uidls = array_slice($uidls, 0, -$skip_count);
		}

		return $uidls;
	}

	function generate( $filename, $uidls, $server, $username )
	{
		verbose('generating popstate file ' . $filename);

		$prog = PROG;
		$vers = VERSION;
		$data =<<< _EOF_
# POP3 State File
# This file generated by $prog/$vers!  Do not edit.

*$server $username

_EOF_;
		$time = time();
		foreach ( $uidls as $uidl )
			$data .= MSG_STATE . ' ' . $uidl . ' ' . $time . PHP_EOL;

		file_put_contents($filename, $data);
	}


	//////////////////////////////////////////////////////////////////////////////
	//	Helper functions
	//

	function skip( $fp )
	{
		while ( $line = read_line($fp) ) 
		{
			if ( '+OK' == substr($line, 0, 3) )
				return;
		}
		if ( false === $line )
			error('failed to read from server', 5);
	}

	function read_lines( $fp, $end, $do_runner = false )
	{
		$result = array();
		while ( $line = read_line($fp) )
		{
			if ( $do_runner )
				runner();

			if ( $end == substr($line, 0, strlen($end)) )
			{
				if ( $do_runner )
					echo ' ' . chr(8);

				return $result;
			}

			$result[] = $line;
		}

		if ( $do_runner )
			echo ' ';

		return $result;
	}

	function read_line( $fp )
	{
		$line = fgets($fp, 2048);
		if ( $line )
		{
			$line = rtrim($line, " \r\n"); 
			debug('READ: ' . $line); 
			if ( '-ERR' == substr($line, 0, 4) )
				error('got error from server: ' . $line, 4);
		}
		return $line;
	}

	function write_line( $fp, $line )
	{
		global $flag_crlf;
		
		debug('PUTS: ' . preg_replace('/^PASS .*$/', 'PASS ********', $line)); 
		fputs($fp, $line . ($flag_crlf ? "\r\n" : "\n"));
	}

	/**
	 *	From: http://docstore.mik.ua/orelly/webprog/pcook/ch20_05.htm
	 */
	function get_password()
	{
		if ( ISWIN )
		{
			// load the w32api extension and register _getch()
			if ( !function_exists('w32api_register_function') )
				if ( !dl('php_w32api.dll') )
					error('failed to load php_w32api.dll');

			w32api_register_function('msvcrt.dll','_getch','int');
			$result = false;
			while(true) {
				// get a character from the keyboard
				$c = chr(_getch());
				if ( "\r" == $c || "\n" == $c ) {
					// if it's a newline, break out of the loop, we've got our password
					break;
				} elseif ("\x08" == $c) {
					/* if it's a backspace, delete the previous char from $password */
					$result = substr_replace($result,'',-1,1);
				} elseif ("\x03" == $c) {
					// if it's Control-C, clear $password and break out of the loop
					$result = NULL;
					break;
				} else {
					// otherwise, add the character to the password
					$result .= $c;
				}
			}
		}
		else
		{
			// disable echo on stdin
			stty_echo(false);

			// read password
			$result = fgets(STDIN, 1024);

			// enable echo again
			stty_echo(true);
		}
		fputs(STDOUT, PHP_EOL);

		if ( false === $result )
			error('failed to read password', 6);

		return rtrim($result, "\r\n");
	}

	/**
	 *	From: http://docstore.mik.ua/orelly/webprog/pcook/ch20_05.htm
	 */
	function stty_echo( $enable = true )
	{
		$cmd = '/bin/stty ' . ($enable ? 'echo' : '-echo');
		//debug('cmd = ' . $cmd);
		`$cmd`;
	}

	function runner()
	{
		static $runners = null;
		static $runner  = 0;
		static $timer	= 0;

		if ( null === $runners )
			$runners = array('-', '\\', '|', '/',);


		$time = substr(microtime(), 0, 3);
		if ( $time != $timer )
		{
			echo $runners[++$runner % count($runners)] . chr(8);
			$timer = $time;
		}
	}

	function error( $message, $exitcode = 2 )
	{
		global $php_errormsg;
		fputs(STDERR, date('Y-m-d H:i:s') . ': ' . PROG . ': ' . $message . ($php_errormsg ? " ($php_errormsg)" : '') . PHP_EOL);
		exit($exitcode);
	}

	function verbose( $message, $do_eol = true )
	{
		global $flag_verbose;

		if ( $flag_verbose )
			echo date('Y-m-d H:i:s') . ' ' . $message . ($do_eol ? PHP_EOL : '');
	}

	function debug( $message )
	{
		global $flag_debug;

		if ( $flag_debug )
			fputs(STDERR, date('Y-m-d H:i:s') . ' DEBUG: ' . $message . PHP_EOL);
	}

	function usage( $message = null )
	{
		fputs(STDERR, 'usage: ' . PROG . " [-d] [-i n] [-s] [-v] [-f file] server [ port ]" . PHP_EOL);
		fputs(STDERR, "\t-c\tCRLF flag, use when talking to Windows servers" . PHP_EOL);
		fputs(STDERR, "\t-d\tdebug flag". PHP_EOL);
		fputs(STDERR, "\t-f\toutput filename (if popstate.dat, Thunderbird needs to be closed!)". PHP_EOL);
		fputs(STDERR, "\t-i\tignore the last n messages (for if you don't have them yet)". PHP_EOL);
		fputs(STDERR, "\t-s\tuse for secure POP3 (SSL/TLS)". PHP_EOL);
		fputs(STDERR, "\t-v\tverbose flag". PHP_EOL);

		if ( $message )
			fputs(STDERR, PROG . ': ' . $message . PHP_EOL);

		exit(1);
	}
