#!/usr/bin/php
<?php
/*
 * open the methods/ folder of a clone of Mastodon's documentation
 * ( git@github.com:mastodon/documentation.git )
 * and extract all methods from the API doc by parsing the Markdown files.
 * if you pass one filename (without path) as argument, it will only parse this MD file for tests.
 * will write to ../assets/methods.json
 * that you can then parse using generate_classes.php to generate the mastodon-api-client code.
 */

// you may define your own paths in config.php
if (is_file(__DIR__."/config.php")) include(__DIR__."/config.php");

if (!defined('MSTDN_DOC_ROOT')) define('MSTDN_DOC_ROOT','/home/benjamin/mastox/mastodon-doc');
if (!defined('METHODS_JSON')) define('METHODS_JSON',__DIR__.'/../assets/methods-new.json');
if (!defined('DEBUG')) define('DEBUG',true);

$data=[];

// ------------------------------------------------------------
// call parse_entity_file on each found md file

if (isset($argv[1])) {
    $allfiles=[MSTDN_DOC_ROOT.'/content/en/methods/'.$argv[1]];
} else {
    $allfiles=glob(MSTDN_DOC_ROOT.'/content/en/methods/*.md');
    if (!count($allfiles)) die('No MD files found');
}

sort($allfiles);
foreach($allfiles as $file) {
    parse_method_file($file);
}

// sort methods by name before writing them:
ksort($data);

echo "Saving file...\n";
file_put_contents(
    METHODS_JSON,
    json_encode(["namespaces" => $data],JSON_PRETTY_PRINT)
);

$out=[];
exec('git -C '.escapeshellarg(MSTDN_DOC_ROOT).' rev-parse HEAD',$out);
echo "Saved from mastodon-doc at commit ".$out[0];
// save the current commit of the mastodon documentation to the same file but named .commit :
file_put_contents(
    substr(METHODS_JSON,0,-4).'commit',
    $out[0]
);

echo "\n";


/**
 * parse one entity file and extract a hash of information from it.
 * save it into the global $data[entityname] 
 */
function parse_method_file($file) {
    global $data;

    $filename=substr(basename($file),0,-3); // removes .md : this is the "namespace" 
    echo "Will parse ".$filename."\n";
    $f=fopen($file,'rb');
    if (!$f) die('impossible to open file '.$file);

    // this is a finite state machine that searches for patterns in the MD file:
    $state=0;

    $namespace=[ ];
    $method=[];
    $nextismethod=false;
    /* finite state machine only used when entering blocks a
     *  such as #### Request ##### Form data parameters etc. 
     */
    $state=0; 
    $linecount=0;
    
    while ($line=fgets($f,8192)) {
        $linecount++;
        if (!trim($line)) continue; // skip blank lines
        
        if (DEBUG) echo "L: $line";
        // search for description line once:
        if (!isset($namespace['description']) && preg_match('#description: (.*)#',$line,$mat)) {
            $namespace['description']=$mat[1];
            $namespace['methods']=[]; 
        }
        
        /* each methods has a description line with attributes like :
         * ## Identity proofs {{%deprecated%}} {#identity_proofs}
         * they may appear at any time so they are outside the state machine case:
         */
        if (preg_match('/^## (.*) \{#[^\}]*\}$/',$line,$mat)) {
            if (count($method)) {
                // save previous method:
                $method['namespace']=$filename;
                $method['url']='https://docs.joinmastodon.org/methods/'.$filename.'/#'.$method['name'];
                $namespace['methods'][$method['name']]=$method;
            }
            $method=[]; // new method
            $method['deprecated']=0;
            if (strpos($mat[1],"{{%deprecated%}}")!==false) {
                $mat[1]=str_replace('{{%deprecated%}}','',$mat[1]);
                $method['deprecated']=1;
            }
            $method['description']=trim($mat[1]);
            $method['name']=$mat[2];
            $method['pathParams'] = [];
            $method['queryParams'] = [];
            $method['formParams'] = [];
            
        }

        if ($nextismethod) {
            if (preg_match('#^([A-Z]+) (/[^ ]+) HTTP/1.1#',$line,$mat)) {
                $method['method']=$mat[1];
                $method['uri']=$mat[2];
            }
            $nextismethod=false;
        }

        // next line should be METHOD /url HTTP/1.1
        if (preg_match('/^```http/',$line,$mat)) {
            $nextismethod=true;
        }

        if ($state==1 || $state==2 || $state==3) {
            /* Form / Path / Query data parameters fills the '*Params' hash */

            // each argument is a line with the argument name
            // followed by {{<required>}} Type. description
            if (preg_match('/^([^:#].*)/',$line,$mat)) {
                $formParam=[
                    'name' => trim($mat[1]),
                ];
                
            }
            if (preg_match('/\: (.*)/',$line,$mat)) {
                $formParam['nullable']=0; // will be set to 1 for files
                
                $formParam['required']=0;
                if (strpos($mat[1],'{{<required>}}')!==false) {
                    $mat[1]=trim(str_replace('{{<required>}}','',$mat[1]));
                    $formParam['required']=1;
                }
                // extract the type:
                $typefound=false;
                
                if (strpos($mat[1],'Boolean. ')===0) {
                    $typefound=true;
                    $mat[1]=substr($mat[1],strlen('Boolean. '));
                    $formParam['type']='boolean';
                }
                
                if (!$typefound && strpos($mat[1],'String ([Datetime]')===0) {
                    $typefound=true;
                    $mat[1]=substr($mat[1],strlen('String '));
                    $formParam['type']='datetime';
                }
                
                if (!$typefound && strpos($mat[1],'String. ')===0) {
                    $typefound=true;
                    $mat[1]=substr($mat[1],strlen('String. '));
                    $formParam['type']='string';
                }
                
                if (!$typefound && strpos($mat[1],'Hash. ')===0) {
                    $typefound=true;
                    $mat[1]=substr($mat[1],strlen('Hash. '));
                    $formParam['type']='hash';
                }
                
                if (!$typefound && preg_match('/Array of ([^\.]+). /',$mat[1],$submat) ) {
                    $typefound=true;
                    $mat[1]=substr($mat[1],strlen($submat[0]));
                    $formParam['type']='array<'.$submat[1].'>';
                }
                
                if (!$typefound && strpos($mat[1],'encoded using `multipart/form-data`')===0) {
                    $typefound=true;
                    $formParam['type']='file';
                    $formParam['nullable']=1;
                }
                
                if (!$typefound) {
                    echo 'Type not found on file '.$filename.' line '.$linecount.' text: '.$line."\n";
                }
                
                $formParam['description']=$mat[1];

                switch ($state) {
                case 1:
                    $method['formParams'][]= $formParam;
                    break;
                case 2:
                    $method['queryParams'][]= $formParam;
                    break;
                case 3:
                    $method['pathParams'][]= $formParam;
                    break;
                }
                
            }
        } // state == 1 or 2 or 3 

        
        // In each loop, we change the state only after the case state machine: 
        if (strpos($line,'##### Form data parameters')===0) {
            $state=1;
        }

        if (strpos($line,'##### Query parameters')===0) {
            $state=2;
        }

        if (strpos($line,'##### Path parameters')===0) {
            $state=3;
        }
        
    } // for each line in the file

    /* we MAY end by an unsave method: store it now */
    if (count($method)) {
        $method['namespace']=$filename;
        $method['url']='https://docs.joinmastodon.org/methods/'.$filename.'/#'.$method['name'];
        $namespace['methods'][$method['name']]=$method;
    }

    /* we MAY end here with a non-stored entity too: store it now */
    if (count($namespace)) {
        $data[$filename]=$namespace;
    }

    fclose($f);
}

