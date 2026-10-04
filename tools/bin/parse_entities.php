#!/usr/bin/php
<?php
/*
 * open the entities/ folder of a clone of Mastodon's documentation
 * ( git@github.com:mastodon/documentation.git )
 * and extract all entities from the API doc by parsing the Markdown files.
 * if you pass one filename (without path) as argument, it will only parse this MD file for tests.
 * will write to ../assets/entities.json
 * that you can then parse using generate_classes.php to generate the mastodon-api-client code.
 */

// you may define your own paths in config.php
if (is_file(__DIR__."/config.php")) include(__DIR__."/config.php");

if (!defined('MSTDN_DOC_ROOT')) define('MSTDN_DOC_ROOT','/home/benjamin/mastox/mastodon-doc');
if (!defined('ENTITIES_JSON')) define('ENTITIES_JSON',__DIR__.'/../assets/entities.json');
if (!defined('DEBUG')) define('DEBUG',true);

$data=[];

// ------------------------------------------------------------
// call parse_entity_file on each found md file

if (isset($argv[1])) {
    $allfiles=[MSTDN_DOC_ROOT.'/content/en/entities/'.$argv[1]];
} else {
    $allfiles=glob(MSTDN_DOC_ROOT.'/content/en/entities/*.md');
    if (!count($allfiles)) die('No MD files found');
}

sort($allfiles);
foreach($allfiles as $file) {
    parse_entity_file($file);
}

// sort entities by name before writing them:
ksort($data);

echo "Saving file...\n";
file_put_contents(
    ENTITIES_JSON,
    json_encode($data,JSON_PRETTY_PRINT)
);

echo "Saved from mastodon-doc at commit ";
$out=[]
exec('git -C '.escapeshellarg(MSTDN_DOC_ROOT).' rev-parse HEAD',$out);
// save the current commit of the mastodon documentation to the same file but named .commit :
file_put_contents(
    substr(ENTITIES_JSON,0,-4).'.commit',
    $out[0]
);

echo "\n";


/**
 * parse one entity file and extract a hash of information from it.
 * save it into the global $data[entityname] 
 */
function parse_entity_file($file) {
    global $data;

    $filename=substr(basename($file),0,-3); // removes .md
    echo "Will parse ".$filename."\n";
    $f=fopen($file,'rb');
    if (!$f) die('impossible to open file '.$file);

    // this is a finite state machine that searches for patterns in the MD file:
    $state=0;
    $entity=[ "parent" => "" ];

    while ($line=fgets($f,8192)) {

        switch ($state) {

            /* first paragraph */
        case 0:
            if (DEBUG) echo "C0: $line";
            if (preg_match('#title: (.*)#',$line,$mat)) {
                $entity['name']=$mat[1];
                $mainEntity=$entity['name']; // in case there are multiple children entities
            }
            if (preg_match('#description: (.*)#',$line,$mat)) {
                $entity['description']=$mat[1];
            }
            if (preg_match('/^```json/',$line)) {
                $state=1;
                $exampleJson="";
            }

            /* we don't always have ```json example, we may have ## Attributes directly: */
            if (preg_match('/^### `(.*)`/',$line,$mat)) {
                $state=3; // go directly there
                $entity['properties']=[];
                
                $currentProperty=[];
                $currentProperty['name']=$mat[1];
                $currentProperty['nullable']=null;
            }
            break;

            /* json example at the head of each entity */
        case 1:
            if (DEBUG) echo "C1: $line";
            if (preg_match('/^```/',$line)) {
                $state=2;
                $entity['exampleJson']=$exampleJson;
                unset($exampleJson);
            } else
                $exampleJson.=$line;
            break;

            /* search for entity attributes H2 */
        case 2:
            if (DEBUG) echo "C2: $line";
            /* if we find a *new* ## <subentity name> this means we start a child entity, let's handle it (LATER ;) ) */
            if (preg_match('/^## Attributes/',$line)) {
                $state=3;
                $currentProperty=[];
                $entity['properties']=[];
            }

            // we may have directly a ### attribute, (especially for child entities)
            if (preg_match('/^### `(.*)`/',$line,$mat)) {
                $state=3; // go directly there
                $entity['properties']=[];
                
                $currentProperty=[];
                $currentProperty['name']=$mat[1];
                $currentProperty['nullable']=null;
            }
            
            break;

            /* search for an attribute */
        case 3:
            if (DEBUG) echo "C3: $line";
            if (preg_match('/^### `(.*)`(.*)/',$line,$mat)) {
                if (DEBUG) echo "found an attribute: ".$mat[1]."\n";
                /* we start the next one: */
                if (count($currentProperty)) {
                    $entity['properties'][]=$currentProperty;
                    $currentProperty=[];
                }
                $currentProperty['name']=$mat[1];
                $currentProperty['nullable']=null;

                // if the attribute header says '{{%optional%}}' we set it as nullable :
                if (strpos($mat[2],"{{%optional%}}")!==false) {
                    $currentProperty['nullable']=1;
                }
                
                if (DEBUG) { echo "currentProperty: "; print_r($currentProperty); echo "\n"; }
                break;
            }

            /* then get the properties of that attribute: */
            if (preg_match('/^\*\*Description:\*\* (.*)/',$line,$mat)) {
                $currentProperty['description']=rtrim($mat[1],'\\');
            }
            if (preg_match('/^\*\*Type:\*\* *(.*)/',$line,$mat)) {

                /* this part of the code parses the different cases for Type to convert them
                 * to something that the PHP API would like 
                 */
                $mat[1]=rtrim($mat[1],'\\');
                if (substr($mat[1],0,14)=='{{<nullable>}}') {
                    $currentProperty['nullable']=1;
                    $mat[1]=substr($mat[1],15);
                }

                $typeisarray=false;
                
                // arrays of [Objectclass]
                if (preg_match('/Array of ([^ ]*)/',$mat[1],$submat)) {
                    $typeisarray=true;
                    $mat[1]=$submat[1];
                }

                // **Type:** String ([Datetime](/api/datetime-format#datetime))\
                // datetime is a string with subattribute: 
                if (preg_match('/String.*\[Datetime\]/',$mat[1],$submat)) {
                    $currentProperty['type']= (($typeisarray)?'array<':'') . 'datetime' . (($typeisarray)?'>':'');
                    break;
                }

                if (preg_match('/String.*\[Date\]/',$mat[1],$submat)) {
                    $currentProperty['type']= (($typeisarray)?'array<':'') . 'datetime' . (($typeisarray)?'>':'');
                    break;
                }

                // named type using [Objectclass]
                if (preg_match('/^\[([^\]]*)\]/',$mat[1],$submat)) {
                    $currentProperty['type']=(($typeisarray)?'array<':'') . $submat[1] . (($typeisarray)?'>':'');
                    break;
                }
                
                // standard types
                if (preg_match('/^([^, ]*)/',$mat[1],$submat)) { // may end with ' ' or ','
                    $currentProperty['type']=(($typeisarray)?'array<':'') . strtolower($submat[1]) . (($typeisarray)?'>':'');
                    break;
                }
                die('Type not understood: '.$mat[1]);
            }
            
            /* we exit this serie when we entounter either the END of the file, or a new H2 : */
            if (preg_match('/^## (.*)/',$line)) {
                if (DEBUG) echo "C3 match '## '\n";
                if (count($currentProperty)) {
                    // we save the last attribute:
                    $entity['properties'][]=$currentProperty;
                    $currentProperty=[];
                }
                
                // then we have different cases :
                // if this is a child entity we have something like this:
                // ## MutedAccount entity attributes {#MutedAccount}
                // or if this is the end or something else we have that:
                // ## See also
                if (preg_match('/^## ([^ ]*) entity attributes/',$line,$mat)) {
                    // child entity :
                    // save the current one:
                    $entity['url']='https://docs.joinmastodon.org/entities/'.$filename.'/';
                    // subentities uses a Anchor url:
                    if ($entity['parent']) { $entity['url'].='#'.$entity['name']; }
                    
                    $data[$entity['name']]=$entity;
                    // start a new one:
                    $entity=[ 'parent' => $mainEntity, 'name' => $mat[1] ];
                    $state=0; // go back to that state for a CHILD entity
                } else {
                    // we are not into a sub entity, normally the file is finished ...
                    if (DEBUG) echo "Not in subentity, line is ".$line."\n";
                }

            }
            break;
            
            
        } // switch on states

        
    } // for each line in the file

    /* we MAY end by an attribute, which means we didn't store it in the array: store it now */
    if (isset($currentProperty) && count($currentProperty)) {
        $entity['properties'][]=$currentProperty;
        $currentProperty=[];
    }
    /* we MAY end here with a non-stored entity too: store it now */
    if ($entity['name']) {
        $entity['url']='https://docs.joinmastodon.org/entities/'.$filename.'/';
        // subentities uses a Anchor url:
        if ($entity['parent']) { $entity['url'].='#'.$entity['name']; }
        $data[$entity['name']]=$entity;
    }

    fclose($f);
}

