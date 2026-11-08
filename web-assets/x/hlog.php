<?php
$l=@file_get_contents("/tmp/eg-webs.log"); echo implode("\n", array_filter(explode("\n",$l), fn($x)=>strpos($x," | ")!==false || strpos($x,"server ip")!==false));
