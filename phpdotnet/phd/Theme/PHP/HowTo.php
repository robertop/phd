<?php
namespace phpdotnet\phd;
/*  $Id$ */


class Theme_PHP_HowTo extends Theme_PHP_Web
{
    private $tmp = "";
    public function __construct(array $IDs, $filename, $ext = "php") {
        phpdotnet::__construct($IDs, array(), $ext, true);
        $this->outputdir = Config::output_dir() . 'howto' . DIRECTORY_SEPARATOR;
        if(!file_exists($this->outputdir) || is_file($this->outputdir)) {
            mkdir($this->outputdir) or die("Can't create the cache directory");
        }
    }

    public function header($id) {
        $title = Helper::getDescription($id, true);
        $parent = Helper::getParent($id);
        $next = $prev = $up = array(null, null);
        if ($parent && $parent != "ROOT") {
            $siblings = Helper::getChildren($parent);
            $prev = parent::createPrev($id, $parent, $siblings);
            $next = parent::createNext($id, $parent, $siblings);
            $up = array($parent.".php", Helper::getDescription($parent, false));
        }

        $this->tmp = <<< NAV
<div style="text-align: center;">
 <div class="prev" style="text-align: left; float: left;"><a href="{$prev[0]}">{$prev[1]}</a></div>
 <div class="next" style="text-align: right; float: right;"><a href="{$next[0]}">{$next[1]}</a></div>
 <div class="up"><a href="{$up[0]}">{$up[1]}</a></div>
</div>
NAV;
        return "<?php include_once '../include/init.inc.php'; echo site_header('$title');?>\n" . $this->tmp . "<hr />\n";
    }
    public function footer($id) {
        return "<hr />\n" . $this->tmp . "<br />\n<?php echo site_footer(); ?>\n";
    }
}

/*
* vim600: sw=4 ts=4 fdm=syntax syntax=php et
* vim<600: sw=4 ts=4
*/

