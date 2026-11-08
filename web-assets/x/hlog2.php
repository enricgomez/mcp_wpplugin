<?php
$l=(string)@file_get_contents("/tmp/eg-webs2.log"); echo "bytes ", strlen($l), "\n", substr(preg_replace("/^PHP.*$/m","",$l),-6000);
