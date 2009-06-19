<?php
namespace phpdotnet\phd;

class Theme_PEAR_BigHTML extends Theme_PEAR
{
    /**
    * File to write the html to
    *
    * @var string
    */
    public $outputfile = null;

    /**
    * We do not generate multiple chunks
    *
    * @var boolean
    */
    public $chunked = false;

    public function __construct(array $IDs, $ext = "html") {
        parent::__construct($IDs, $ext, false);
        $this->outputfile = Config::output_dir() . 'pear_manual_' . Config::language() . '.html';
        $this->stream = fopen($this->outputfile, "w");
        self::header();
    }
    public function appendData($data, $isChunk) {
        if($isChunk) {
            $data .= "<hr />";
        }
        return fwrite($this->stream, $data);
    }
    public function __destruct() {
        self::footer();
        fclose($this->stream);
    }
    public function header() {
        fwrite($this->stream, '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
 <head>
  <title>PEAR Manual</title>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
 </head>
 <body>
  <div id="doc3">
   <div id="body">
');

    }
    public function footer() {
        fwrite($this->stream, "</div></div></body></html>");
    }

    public function format_qandaset($open, $name, $attrs) {
        if ($open) {
            $this->cchunk["qandaentry"] = array();
            $this->ostream = $this->stream;
            $this->stream = fopen("php://temp/maxmemory", "r+");
            return '';
        }

        $stream = $this->stream;
        $this->stream = $this->ostream;
        unset($this->ostream);
        rewind($stream);

        return parent::qandaset($stream);
    }
}

