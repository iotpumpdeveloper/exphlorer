<?php
/**
 * a tool to explore and profile a php web application completely in cli
 * This is a proof-of-concept, more optimizations need to be made
 * This tool will trace the list of function calls in an app.
 */
if ($_SERVER["argc"] === 1) {
    print "Usage: php {__FILE__} [profile json file]\n";
    exit();
}

$traceContent = "";
if ($_SERVER["argc"] === 2) {
    $profileJSONFILE = $_SERVER["argv"][1];
    $curFile = __FILE__;
    $curDir = __DIR__;
    $traceFile = "$curDir/trace.txt";
    $cmd = "SPX_ENABLED=1 SPX_REPORT=trace SPX_TRACE_FILE=$traceFile php $curFile $profileJSONFILE trace > /dev/null 2>&1";
    shell_exec($cmd);
    //now try only get the relavant information
    $traceContent = file_get_contents($traceFile);
    if (strlen($traceContent) > 0) {
        $lines = explode("\n", trim($traceContent));
        $numOfLines = count($lines);
        $resultContent = "";
        $tracingFiles = [];
        for ($i = 0; $i < $numOfLines; $i++) {
            $lineComps = explode("|", $lines[$i]);

            $depth = 1;
            //modify depth
            if (isset($lineComps[6])) {
                $depth = (int)$lineComps[6];
                if ($depth > 0) {
                    $lineComps[6] = $depth - 1;
                    $lineComps[6] = str_pad($lineComps[6], 10," ");
                }
                $lines[$i] = implode("|", $lineComps);
            }

            $shouldAddToResult = true;
            //filter out unrelated information
            if (isset($lineComps[7]) && strpos($lines[$i],$curFile) !== FALSE) {
                $shouldAddToResult = false;
            }  else if (isset($lineComps[7]) && strpos($lines[$i], ".php") !== FALSE) {
                if (strpos($lines[$i], "-") !== FALSE) {
                    $shouldAddToResult = false;
                } else {
                    //this is a php file
                    $file = str_replace("+/","", trim($lineComps[7]));
                    $tracingFiles[] = $file;
                }
            } else if (isset($lineComps[7]) && strpos($lines[$i], ".php") === FALSE) {
                if (strpos($lineComps[7], "+") !== FALSE) {
                    //this is a function call
                    $token = str_replace("+", "", trim($lineComps[7]));
                    if (strpos($token, "{closure}") === FALSE) {
                        //now this is a function or method call
                        //look for the file context
                        $tokenComps = explode("::", $token);
                        $functionName = "";
                        if (count($tokenComps) === 2) {
                            $functionName = $tokenComps[1];
                        } else {
                            $functionName = $tokenComps[0];
                        }
                        $targetFileToSearch = "/".$tracingFiles[count($tracingFiles) -1];
                        $functionFinder = '/function[\s\n]+(\S+)[\s\n]*\(/';
                        $targetFileContent = file_get_contents($targetFileToSearch);
                        preg_match_all( $functionFinder , $targetFileContent , $matches, \PREG_OFFSET_CAPTURE);
                        foreach($matches[0] as $match) {
                            if (strpos($match[0], "$functionName") !== FALSE) {
                                $charPos = $match[1];
                                list($before) = str_split($targetFileContent, $charPos);
                                $lineNumber = strlen($before) - strlen(str_replace("\n", "", $before)) + 1;
                                $resultContent .= "* ".$targetFileToSearch."(".$lineNumber.") ".$token."\n";
                            }
                        }
                    }
                }
                if (strpos($lines[$i], "Function") !== FALSE) {
                    $lineComps[7] = "File";
                    $shouldAddToResult = true;
                } else {
                    $shouldAddToResult = false;
                }
            }
        }
        //now write the result content to the file
        file_put_contents($traceFile, $resultContent);
    }
}

if ( ($_SERVER["argc"] === 2) || ($_SERVER["argc"] === 3 && $_SERVER['argv'][2] === 'trace')) {
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

    //fix current path
    chdir($profileData["DOCUMENT_ROOT"]);
    $pwd = getcwd();
    $_SERVER['PWD'] = $pwd;

    //now include the entry file
    require_once $profileData["SCRIPT_FILENAME"];
}
