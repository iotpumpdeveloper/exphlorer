<?php
/**
 * a tool to explore and profile a php web application completely in cli
 * This is a proof-of-concept, more optimizations need to be made
 * This tool will trace the list of function calls in an app.
 */
if ($_SERVER["argc"] === 1) {
    print "Usage: php {__FILE__} [profile json file]\n";
    print "Usage: php {__FILE__} [profile json file] trace\n";
    print "Usage: php {__FILE__} [profile json file] trace [REQUEST_URI]\n";
    print "Usage: php {__FILE__} [profile json file] run [REQUEST_URI]\n";
    exit();
}

$traceContent = "";
if ($_SERVER["argc"] === 2 || ($_SERVER["argc"] > 2 && $_SERVER['argv'][2] === 'trace')) {
    $profileJSONFILE = $_SERVER["argv"][1];
    $curFile = __FILE__;
    $curDir = __DIR__;
    $traceFile = "/usr/local/exphlorer/trace.txt";
    $cmd = "";
    if (isset($_SERVER['argv'][3])) {
        $requestUri = $_SERVER['argv'][3];
        $cmd = "SPX_ENABLED=1 SPX_REPORT=trace SPX_TRACE_FILE=$traceFile php $curFile $profileJSONFILE run $requestUri > /dev/null 2>&1";
    } else {
        $cmd = "SPX_ENABLED=1 SPX_REPORT=trace SPX_TRACE_FILE=$traceFile php $curFile $profileJSONFILE run > /dev/null 2>&1";
    }
    shell_exec($cmd);
    //now try only get the relavant information
    $traceContent = file_get_contents($traceFile);
    file_put_contents("trace_raw.txt", $traceContent);
    if (strlen($traceContent) > 0) {
        $lines = explode("\n", trim($traceContent));
        $numOfLines = count($lines);
        $resultLines = [];
        $tracingFiles = [];
        for ($i = 0; $i < $numOfLines; $i++) {
            $line = $lines[$i];
            $shouldAddToResult = true;

            $lineLowercase = strtolower($line);
            if (strpos($lineLowercase, "+composer") !== FALSE || strpos($lineLowercase, "-composer") !== FALSE) {
              $shouldAddToResult = false;
            } else if (strpos($line, ".php") !== FALSE) {
              $shouldAddToResult = false;
            } else if (strpos($line, "-") !== FALSE) {
              $shouldAddToResult = false;
            }

            if ($shouldAddToResult) {
              $resultLines[] = $lines[$i];
            }
        }

        $resultContent = implode("\n", $resultLines);

        //now write the result content to the file
        file_put_contents($traceFile, $resultContent);

        //now run the script again by setting the the mode to "run"
        $_SERVER['argv'][2] = 'run';
    }
}

if ( ($_SERVER["argc"] === 2) || ($_SERVER["argc"] > 2 && $_SERVER['argv'][2] === 'run')) {
  $profileJSONFILE = $_SERVER["argv"][1];
  $profileData = json_decode(file_get_contents($profileJSONFILE), true);

  //populate the computed fields
  $profileData["PHP_SELF"] = $profileData["SCRIPT_NAME"];
  $profileData["SCRIPT_FILENAME"] = $profileData["DOCUMENT_ROOT"]."/".$profileData["SCRIPT_NAME"];
  $profileData["PATH_TRANSLATED"] = $profileData["SCRIPT_FILENAME"];

  $serverProtocolType = substr($profileData["SERVER_PROTOCOL"], 0, 5);

  if ($serverProtocolType === "HTTP/") {
    if ($profileData["REQUEST_METHOD"] === "GET") {
      $profileData["QUERY_STRING"] = implode(",", $_GET);
    } else {
      $profileData["QUERY_STRING"] = [];
    }
  }

  $profileData["HTTP_HOST"] = $profileData["REMOTE_ADDR"].":".$profileData["REMOTE_PORT"];

  $phpSpecialVars = ["_GET","_POST","_COOKIE"];

  foreach($phpSpecialVars as $varName) {
    $$varName = $profileData[$varName];
  }

  //now populate the $_SERVER vars
  foreach($profileData as $key => $val) {
    if (!in_array($key, $phpSpecialVars)) {
      $_SERVER[$key] = $val;
    }
  }

  //override the REQUEST_URI
  if (isset($_SERVER['argv'][3])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['argv'][3];
    $REQUEST_URI = $_SERVER['argv'][3];
  }

  //fix current path
  chdir($profileData["DOCUMENT_ROOT"]);
  $pwd = getcwd();
  $_SERVER['PWD'] = $pwd;

  // fake it so that this is not a console app
  $autoloadPaths = [
    $pwd.'/../vendor/autoload.php',
    $pwd.'/vendor/autoload.php'
  ];
  foreach($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
      require_once $autoloadPath;
      break;
    }
  }

  //deal with zend framework, fake it as non-cli app
  if (class_exists("Zend\Console\Console")) {
    Zend\Console\Console::overrideIsConsole(false);
  }

  //now include the entry file
  require_once $profileData["SCRIPT_FILENAME"];
}
