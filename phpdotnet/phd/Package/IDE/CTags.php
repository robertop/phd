<?php
namespace phpdotnet\phd;
/* $Id$ */

/**
 * This class will render a CTags file containing all of the classes, functions, defines that
 * come in PHP.
 * 
 * The following tag kinds will be generated:
 * c   a class or interface tag
 *     It may have extension field 'i' for the inheritance info (base class, interfaces implemented)
 *     It may have extension field 'a' that will hold the 'final' if class is final
 *     It may have extension field 'm' that will hold the 'abstract' if class is abstract
 * f   a function or class method tag
 *     It may have extension field 'class' that has the name of the class the method is in (method tags only)
 *     It may have extension field 'S' that will have the function's signature. The return type of a method will be
 *     the first word in the signature , for example
 *     string function substr($string, $start, $length)    <-- for a function
 *     string function method($arg1, $arg2)                <-- for a method, it will NOT have any modifiers (private, static,...)
 *
 *     It may have extension field 'a' that will hold the 'static', 'private', or 'protected' or 'final' modifiers (none means public)
 *     It may have extension field 'm' that will hold the 'abstract' if method is abstract
 * d   a predefined constant tag 
 * p   a class member variable tag 
 *     It will have extension field 'class' that has the name of the class the property is in
 *     It may have extension field 'a' that will hold the 'static', 'private', or 'protected' or 'final' modifiers (none means public)
 * o   a class constant tag 
 *     It will have extension field 'class' that has the name of the class the constant is in
 *
 * Kinds p,k deviate from the kinds provided by the default ctags PHP implementation and will most likely
 * not be accessible by most editors.
 *
 * None of the tags will have an ex_cmd (or rather a "0" will be put in its place), since there is no source 
 * file that can be jumped to.
 *
 * None of the tags will have a file name (empty strign) since there is no source file that can be jumped to.
 *
 */
class Package_IDE_CTags extends Package_IDE_Base {

	/**
	 * The opened file pointer (resource) to the tag file that is being created
	 */
	private $tagFile;
	
	/**
	 * The captured class info (class name, inheritance)
	 */
	private $currentClassInfo;
	
	/**
	 * The captured constant info (constant name)
	 */
	private $currentDefineInfo;
	
	/**
	 * The captured info for a class member variable.
	 */
	private $currentPropertyInfo;
	
	/**
	 * The modifiers for a function (protected, static, final). we capture these here because
	 * the base class does not.
	 * This works a bit different than property modifiers, this array will be a map
	 * of all modifiers of all methods of a class. For example:
	 * array('getMessage' => array('protected', 'final'), 'getCode' => array('protected', 'final'))
	 */
	private $functionModifiers;
	
	/**
	 * we want to capture both function name and the modifiers; since they are in adjacent tags
	 * we need to the modifiers when we see a modifier, the save it into the functionModifiers list
	 * once we read the function name node.
	 */
	private $currentFunctionModifiers;
	
	/**	
	 * This will keep track of which nodes are currently being iterated through.
	 * Each record in this map will have the XML tag name as its key and
	 * the value is a boolean value where TRUE means that currently
	 * the tag is open. For example, if 'classsynopsisinfo' and 'classname'
	 * tags are both set to TRUE it means that we are currently iterating
	 * inside of a classname tag which is inside of a classsynopsisinfo tag.
	 * Using this map we can easily check that we are in a relevant
	 * node in the hierachy.
	 */
	private $openNodes = array(
	
		// tags that hold classes
		'classsynopsisinfo' => FALSE,
		'classname' => FALSE,
		'ooclass' => FALSE,
		'modifier' => FALSE,
		
		// tags that hold constants
		'appendix' => FALSE,
		'varlistentry' => FALSE,
		'term' => FALSE,
		
		// tags that hold class variables (properties)
		'fieldsynopsis' => FALSE,
		
		// tags that hold the predefined variables
		'varentry' => FALSE,
		'simplelist' => FALSE,
		
		// tags that hold function info
		'methodparam' => FALSE
	);
	
	/**
	 * functions that are aliases of other functions. the DOC
	 * does NOT document the proper signature, we must lookup the 
	 * proper signature by looking up the signature of the function
	 * that is being aliased.  we perform two passes; we process
	 * function aliases last.
	 * There may be multiple aliases for a function.
	 * @var array of   original name => array(alias names)
	 */
	private $functionAliases;
	
	/**
	 * @var array of all function aliases, used to check to see if a
	 *      function is an alias
	 */
	private $allFunctionAliases;
	
	/**
	 * the value of the purpose text tag contents
	 * this is used to determine if the current function is
	 * an alias of another one.
	 */
	private $refPurposeText;
	
	public function __construct() {
        $this->registerFormatName('CTags');
        $this->setExt(Config::ext() === null ? ".php" : Config::ext());
		$this->tagFile = NULL;
    }
	
	/**
	 * When this format is constructed, create the tags file where all of the tags will go.
	 * We only want 1 tag file for the entire list of functions.
	 */
	public function INIT($value) {
		parent::INIT($value);
		$fileName = Config::output_dir() . strtolower($this->getFormatName()) . '/php.tags';
		$this->tagFile = fopen($fileName, 'wb');
		if (!$this->tagFile) {
			v("Output file '{$fileName}' cannot be opened for writing.", E_USER_ERROR);
		}
		$this->openNodes = array(
			'part' => FALSE,
			'classsynopsisinfo' => FALSE,
			'classname' => FALSE,
			'ooclass' => FALSE,
			'modifier' => FALSE,
			'appendix' => FALSE,
			'varlistentry' => FALSE,
			'term' => FALSE,
			'fieldsynopsis' => FALSE,
			'varentry' => FALSE,
			'simplelist' => FALSE,
			'methodparam' => FALSE
		);
		$this->functionAliases = array();
		$this->allFunctionAliases = array();
		
		$header = <<<EOF
!_TAG_FILE_FORMAT	2	/extended format; --format=1 will not append ;" to lines/
!_TAG_FILE_SORTED	0	/0=unsorted, 1=sorted, 2=foldcase/
!_TAG_PROGRAM_AUTHOR	Roberto Perpuly	/roberto@triumph4php.com/
!_TAG_PROGRAM_NAME	PhD - PHP DocBook	//
!_TAG_PROGRAM_URL	http://doc.php.net/phd/	/official site/
!_TAG_PROGRAM_VERSION	1.1.3 forked see https://github.com/robertop/phd	//
EOF;
		if ($this->tagFile) {
			 fwrite($this->tagFile, $header);
			 fwrite($this->tagFile, "\n");
		}
	}
	
	/**
	 * properly close the file after we have consumed throught all of the functions.
	 */
	public function FINALIZE($value) {
		parent::FINALIZE($value);
		if ($this->tagFile) {
			fclose($this->tagFile);
			$this->tagFile = NULL;
		}
		if (!count($this->functionAliases)) {
			return;
		}
				
		$this->handleFunctionAliases();
	}
	
	/**
	 * this method handles function aliases; the PHP documentation will
	 * not explictly have the function signatures for function aliases; but
	 * we want the signature to be in the tag file.  Therefore, we  for function
	 * aliases we must lookup the function being aliases, and make that be
	 * the new function's signature as well.
	 * This function assumes that only function are aliased; not methods.
	 */
	private function handleFunctionAliases() {
		$fileName = Config::output_dir() . strtolower($this->getFormatName()) . '/php.tags';
		$tagFile = fopen($fileName, 'ab+');
				
		// i guess we loop through the tag file to find the aliases
		$tagLines = array();
		while (!feof($tagFile)) {
			$line = fgets($tagFile);
			$tag = explode("\t", $line);
			$isMethodTag = FALSE;

			foreach ($tag as $tagItem) {
				if (stripos($tagItem, "class:") !== FALSE) {
					$isMethodTag = TRUE;
					break;
				}
			}
			
			// the function name is the first column of the tag line			
			// make sure to methods
			if (!$isMethodTag && isset($this->functionAliases[$tag[0]])) {

				// replace the aliased function name with the
				// new name
				// there may be multiple aliases for a function
				$aliases = $this->functionAliases[$tag[0]];
				foreach ($aliases as $alias) {
					$tagLines[] = str_replace(
						$tag[0],
						$alias,
						$line
					);
				}
			}
		}
		
		// now we have all the signature lets write out the tags
		fseek($tagFile, -1, SEEK_END);
		foreach ($tagLines as $line) {
			fputs($tagFile, $line);
		}
		
		fclose($tagFile);
	}
	
	public function STANDALONE($value) {
		
		// the nodes that contain class info
		$this->elementmap['classsynopsisinfo'] = 'format_classsynopsisinfo';
		$this->elementmap['ooclass'] = 'format_ooclass';
		$this->textmap['classname'] = 'format_classname_text';
		$this->textmap['modifier'] = 'format_modifier_text';
		$this->textmap['interfacename'] = 'format_interfacename_text';
		
		// the nodes that contain constants [define()'s] info
		// 
		// <appendix xmlns="http://docbook.org/ns/docbook" xml:id="id3.constants">
		// <title>Predefined Constants</title>
		// <para>
		//  ...
		// <variablelist>
		// <varlistentry xml:id="constant.id3-v1-0">
		// <term> <constant>ID3_V1_0</constant> (<type>integer</type>) </term>
		// ...
		// </varlistentry>
		$this->elementmap['appendix'] = 'format_appendix';
		$this->elementmap['term'] = 'format_term';
		$this->elementmap['varlistentry'] = 'format_varlistentry';
		$this->textmap['constant'] = 'format_constant_text';
		
		// nodes that contain class properties. note: modifier tag already handled above
		// <fieldsynopsis>
		// <modifier>protected</modifier>
		// <type>string</type>
		// <varname linkend="exception.props.message">message</varname>
		// </fieldsynopsis>
		//
		// there is also another way that constants are documented
		//
		//  <fieldsynopsis>
		//  <modifier>const</modifier>
		//  <type>integer</type>
		//  <varname
		//    linkend="filesystemiterator.constants.current-as-pathname">
		//    FilesystemIterator::CURRENT_AS_PATHNAME
		//  </varname>
		//  <initializer>32</initializer>
		//  </fieldsynopsis>
		$this->elementmap['fieldsynopsis'] = 'format_fieldsynopsis';
		$this->textmap['varname'] = 'format_varname_text';
		
		// the method modifiers
		$this->elementmap['methodsynopsis'] = 'format_methodsynopsis';
		$this->textmap['methodname'] = 'format_methodname_text';
		
		// parameters
		$this->elementmap['parameter'] = 'format_parameter';
		
		// for predefined exceptions
		$this->elementmap['part'] = 'format_part';
		
		// for predefined variables
		$this->elementmap['phpdoc:varentry'] = 'format_varentry';
		$this->elementmap['simplelist'] = 'format_simplelist';
	
		// for handling of "aliased" functions
		// for example fwrite / fputs
		$this->textmap['function'] = 'format_function_text';
		$this->textmap['refpurpose'] = 'format_refpurpose_text';
		$this->elementmap['refpurpose'] = 'format_refpurpose';
		
		parent::STANDALONE($value);
	}
	
	/**
	 * captures the class name when traversing the classsynopsisinfo node
	 */
	public function format_classsynopsisinfo($open, $name, $attrs, $props) {
		$this->openNode('classsynopsisinfo', $open);
		$this->closeNodes('ooclass', 'classname', 'modifier');
		$hasRole = isset($attrs[Reader::XMLNS_DOCBOOK]['role']) && strlen($attrs[Reader::XMLNS_DOCBOOK]['role']) > 0;
		if ($open) {			
			if (!$hasRole) { 
			
				// clear out any previous info whe the class tag is opened
				// but only clear it in the class tag and not the comment sections
				// because the property tags need the class name
				$this->currentClassInfo = array(
					'modifier' => '',
					'name' => '',
					'extends' => '',
					'implements' => array()
				);
				$this->functionModifiers = array();
			}
			return;
		}
		
		// only handle the classsynopsisinfo tag that does not have a role
		if ($hasRole) {
			return;
		}
		$this->writeTag($this->renderClass());
	}
	
	public function format_ooclass($open, $name, $attrs, $props) {
		$this->openNode('ooclass', $open);
	}
	
	public function format_classname_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			return;
		}
		if ($this->areNodesOpen('modifier')) {
			$this->currentClassInfo['extends'] = $value;
		}
		else {
			$this->currentClassInfo['name'] = $value;
		}
	}
	
	public function format_modifier_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass') && !$this->areNodesOpen('fieldsynopsis') && !$this->areNodesOpen('methodsynopsis')) {
			return;
		}
		if ($this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			$this->openNode('modifier', strcasecmp($value, 'extends') == 0);
		}
		else if ($this->areNodesOpen('fieldsynopsis')) {
			if (strcasecmp($value, 'protected') == 0) {
				$this->currentPropertyInfo['protected'] = TRUE;
			}
			else if (strcasecmp($value, 'static') == 0) {
				$this->currentPropertyInfo['static'] = TRUE;
			}
			else if (strcasecmp($value, 'final') == 0) {
				$this->currentPropertyInfo['final'] = TRUE;
			}
			else if (strcasecmp($value, 'const') == 0) {
				
				// some properties are labelled as const  (FileSystemIterator)
				$this->currentPropertyInfo['const'] = TRUE;
			}
		}
		else if ($this->areNodesOpen('methodsynopsis') && strcasecmp($value, 'public') != 0) {
		
			// everything is public by default, no need to show it in the tag file.
			$this->currentFunctionModifiers[] =  $value;
		}
	}
	
	public function format_interfacename_text($value, $tag) {
		if (!$this->areNodesOpen('classsynopsisinfo', 'ooclass')) {
			return;
		}
		$this->currentClassInfo['implements'][] = $value;
	}
	
	private function renderClass() {
		$name = $this->currentClassInfo['name'];
		$allBases = array();
		if ($this->currentClassInfo['extends']) {
			$allBases[] = $this->currentClassInfo['extends'];
		}
		if ($this->currentClassInfo['implements']) {
			array_merge($allBases, $this->currentClassInfo['implements']);
		}
		$extends = join(',', $allBases);
		$file = '';
		$exCmd = '0;"';
		$data = "{$name}\t{$file}\t{$exCmd}\tc\t";
		if ($extends) {
			$data .= 'i:' . $extends;
		}
		return $data;
	}
	
	public function format_appendix($open, $name, $attrs, $props) {
		$this->openNode('appendix', $open);
		$this->closeNodes('varlistentry', 'term');
	}
	
	public function format_varlistentry($open, $name, $attrs, $props) {
		if (!$this->areNodesOpen('appendix')) {
			return;
		}
		$this->openNode('varlistentry', $open);
		if ($open) {
			$this->currentDefineInfo = '';
			return;
		}
		 
		if ($this->currentDefineInfo) {
			$this->writeTag($this->renderDefine());
		}
	}
	
	public function format_term($open, $name, $attrs, $props) {
		$this->openNode('term', $open);
	}
	
	public function format_constant_text($text, $node) {
		if (!$this->areNodesOpen('appendix', 'varlistentry', 'term')) {
			return;
		}
		$this->currentDefineInfo = $text;
	}
	
	public function format_refpurpose($open, $name, $attrs, $props) {
		$this->refPurposeText = '';
	}
		
	public function format_refpurpose_text($text, $node) {
		$this->refPurposeText = $text;
	}
	
	public function format_function_text($text, $node) {
		if (!isset($this->cchunk['funcname'])) {
			return;
		}
		if (!is_array($this->cchunk['funcname'])) {
			return;
		}
		if (!$this->isFunctionRefSet) {
			return;
		}
		
		// need to make sure that the current "function" node is inside
		// the purpose node, and the purpose node mentions that this 
		// is an alias
		if (stripos($this->refPurposeText, 'alias') === FALSE) {
			return;
		}
		if (!isset($this->functionAliases[$text])) {
			$this->functionAliases[$text] = array();
		}
		$this->functionAliases[$text][] = $this->cchunk['funcname'][0];
		$this->allFunctionAliases[$text] = $this->cchunk['funcname'][0];
	}
	
	private function renderDefine() {
	
		// class constants also commented using the constant tag, but they contain
		// the scope resolution operator. output the proper tag based on
		// whether the constant is a class constant or not.
		$indexScopeResolution = stripos($this->currentDefineInfo, '::');
		$className = '';
		$defineName = $this->currentDefineInfo;
		$file = '';
		$exCmd = '0;"';
		$tag = "{$defineName}\t{$file}\t{$exCmd}\td";
		if ($indexScopeResolution !== FALSE) {
			$className = substr($defineName, 0, $indexScopeResolution);
			$defineName = substr($defineName, $indexScopeResolution + 2); // 2 = skip the'::'
			$tag = "{$defineName}\t{$file}\t{$exCmd}\to\tclass:{$className}";
		}
		return $tag;
	}
	
	private function renderClassConstant() {
	
		// class constants also sometimes commented using the fields with a
		// constant modifier tag, but they contain
		// the scope resolution operator. output the proper tag based on
		// whether the constant is a class constant or not.
		$indexScopeResolution = stripos($this->current, '::');
		$className = '';
		$defineName = $this->currentDefineInfo;
		$file = '';
		$exCmd = '0;"';
		$tag = "{$defineName}\t{$file}\t{$exCmd}\td";
		if ($indexScopeResolution !== FALSE) {
			$className = substr($defineName, 0, $indexScopeResolution);
			$defineName = substr($defineName, $indexScopeResolution + 2); // 2 = skip the'::'
			$this->currentPropertyInfo = array(
				'name' => $defineName,
				'protected' => FALSE,
				'static' => TRUE,
				'final' => FALSE,
				'const' => TRUE,
			);
			$this->currentClassInfo['name'] = $className;
			$tag = $this->renderProperty();
		}
		return $tag;
	}
	
	public function format_varname_text($text, $node) {
		if (!$this->areNodesOpen('fieldsynopsis') && !$this->areNodesOpen('varentry', 'simplelist')) {
			return;
		}
		if ($this->areNodesOpen('fieldsynopsis')) {
			$this->currentPropertyInfo['name'] = $text;
		}
		else if ($this->areNodesOpen('varentry', 'simplelist')) {
			$this->writeTag($this->renderVariable($text));
		}
	}
	
	public function format_fieldsynopsis($open, $name, $attrs, $props) {
		$this->openNode('fieldsynopsis', $open);
		if ($open) {
			$this->currentPropertyInfo = array(
				'name' => '',
				'protected' => FALSE,
				'static' => FALSE,
				'final' => FALSE,
				'const' => FALSE,
			);
		}
		else {
		
			// some properties are constants, they will have the 
			// scope resultion operator in the name
			// lets separate the class from the constant
			$indexScopeResolution = stripos($this->currentPropertyInfo['name'], '::');
			if ($indexScopeResolution !== FALSE) {
				$className = substr($this->currentPropertyInfo['name'], 0, $indexScopeResolution);
				$constantName = substr($this->currentPropertyInfo['name'], $indexScopeResolution + 2); // 2 = skip the'::'
				$this->currentPropertyInfo['name'] = $constantName;
			}
			$this->writeTag($this->renderProperty());
		}
	}
	
	private function renderProperty() {
		$className = $this->currentClassInfo['name'];
		$propertyName = $this->currentPropertyInfo['name'];
		$file = '';
		$exCmd = '0;"';
		$kind = $this->currentPropertyInfo['const'] ? 'o' : 'p';
		$tag = "{$propertyName}\t{$file}\t{$exCmd}\t{$kind}\tclass:{$className}";
		$access = array();
		if ($this->currentPropertyInfo['protected']) {
			$access[] = 'protected';
		}
		if ($this->currentPropertyInfo['static'] && !$this->currentPropertyInfo['const']) {
			$access[] = 'static';
		}
		if ($this->currentPropertyInfo['const']) {
			$access[] = 'const';
		}
		if ($this->currentPropertyInfo['final']) {
			$access[] = 'final';
		}
		if ($access) {
			$tag .= "\ta:" . join(',', $access);
		}
		$tag .= "\tS:" . join(' ', $access) . " {$className}::{$propertyName}";
		return $tag;
	}
	
	/**
	 * override this method so that we write to the opened file pointer.
	 */
	public function format_refentry($open, $name, $attrs, $props) {
		$this->currentFunctionModifiers = array();
		parent::format_refentry($open, $name, $attrs, $props);
    }

    public function writeChunk() {
        $this->function['name'] = $this->cchunk['funcname'][0];
        $this->function['version'] = $this->versionInfo($this->function['name']);
		foreach ($this->functionSignatures as $signature) {
			
			
			$this->function['return']['type'] = $signature['return_type'];
			$this->function['params'] = $signature['params'];
		
			// dont write function aliases, we must look up the proper signature
			if (!in_array($this->function['name'], $this->allFunctionAliases)) {
				$this->writeTag($this->renderFunction());
				
				if (count($this->cchunk['funcname']) > 1) {
					
					// this is from extensions that have both an oop interface and
					// a procedural interface (for example, MySQLi)
					$this->function['name'] = $this->cchunk['funcname'][1];
					$this->writeTag($this->renderFunction());
				}
			}
		}
    }
	
	public function format_methodsynopsis($open, $name, $attrs, $props) {
		$this->openNode('methodsynopsis', $open);
	}
	
	public function format_methodname_text($text, $node) {
		if (!$this->areNodesOpen('methodsynopsis')) {
			return;
		}
		$this->functionModifiers[$this->toValidName($text)] = $this->currentFunctionModifiers;
	}
	
    private function renderFunction() {
		$name = trim($this->function['name']);
		$isMethod = FALSE;
		$className = '';
		$dotIndex = stripos($name, '.');
		if ($dotIndex !== FALSE) {
		
			// this is a method. parse the class name out of it
			$isMethod = TRUE;
			$className = substr($name, 0, $dotIndex);
			$name = substr($name, $dotIndex + 1);
		}
		$file = '';
		$exCmd = '0;"';
		$signature = $this->renderFunctionDefinition($name);
		$returnType = $this->function['return']['type'];
        $str = "{$name}\t{$file}\t{$exCmd}\tf\tS:{$returnType} {$signature}";
		if ($isMethod) {
			$str .= "\t";
			$str .= 'class:';
			$str .= $className;
			
			// map is indexed by full method name (class '.' method)
			$allModifiers = isset($this->functionModifiers[$this->function['name']]) ? $this->functionModifiers[$this->function['name']] : NULL;
			if ($allModifiers) {
				$accessModifiers = $allModifiers;
				$index = array_search('abstract', $accessModifiers);
				if ($index !== FALSE) {
					unset($accessModifiers[$index]);
				}
				if ($accessModifiers) {
					$str .= "\t";
					$str .= 'a:';
					$str .= join(',', $accessModifiers);
				}
				if ($index !== FALSE) {
					$str .= "\t";
					$str .= 'm: abstract';
				}
			}
		}
		return $str;
    }	

    private function renderFunctionDefinition($functionName) {
        return  
			"function {$functionName}({$this->renderParamBody()})";
    }
	
    private function renderParamBody() {
        $result = array();
        foreach($this->function['params'] as $param) {
			$paramName = '';
			$ref = '';
			if (strcasecmp('reference', $param['role']) == 0) {
				$ref = '&';
			}
			if (strcasecmp($param['optional'], 'true') == 0 && array_key_exists('initializer', $param)
					&& strlen($param['initializer']) > 0) {
				$initializer = $param['initializer'];
                $paramName .= "[ {$ref}\${$param['name']} = {$initializer} ]";
            }
            else if (strcasecmp($param['optional'], 'true') == 0) {
                $paramName .= "[ {$ref}\${$param['name']} ]";
            }
			else {
                $paramName .= "{$ref}\${$param['name']}";
            }
			$result[] = $paramName;
        }

        return implode(", ", $result);
    }
	
	public function format_part($open, $name, $attrs, $opts) {
	
		/**
		 * Predfined exceptions (Exception, ErrorException) are documented outside of the 
		 * function reference. When looking for functions, we must look in the predefined
		 * exceptions part of the manual.
		 * Set the flag so that the base class collects function info
		 */
		if (isset($attrs[Reader::XMLNS_XML]['id']) && $attrs[Reader::XMLNS_XML]['id'] == 'reserved.exceptions') {
			$this->isFunctionRefSet = $open;
		}
	}
	
	public function format_varentry($open, $name, $attrs, $opts) {
		$this->openNode('varentry', $open && isset($attrs[Reader::XMLNS_XML]['id']) && $attrs[Reader::XMLNS_XML]['id'] == 'language.variables.superglobals');
	}
	
	public function format_simplelist($open, $name, $attrs, $opts) {
		$this->openNode('simplelist', $open);
	}
	
	public function format_methodparam($open, $name, $attrs, $props) {
		$this->openNode('methodparam', $open);
		parent::format_methodparam($open, $name, $attrs, $props);
	}
	
	public function format_parameter($open, $name, $attrs, $opts) {
		if (!$this->areNodesOpen('methodparam')) {
			return;
		}
		if ($open) {
			$this->cchunk['param']['role'] = '';
			if (isset($attrs[Reader::XMLNS_DOCBOOK]['role'])) {
				$this->cchunk['param']['role'] = $attrs[Reader::XMLNS_DOCBOOK]['role'];
			}
		}
	}
	
	private function renderVariable($varName) {
		$file = '';
		$exCmd = '0;"';
		return "{$varName}\t{$file}\t{$exCmd}\tv";
	}
	
	/**
	 * @param $nodes,... varargs of strings, one of each node to check
	 * @return boolean TRUE if ALL of the given nodes are currently open
	 */
	private function areNodesOpen() {
		$nodes = func_get_args();
		$allOpen = count($nodes) > 0;
		foreach ($nodes as $node) {
			if (!$allOpen) {
				break;
			}
			$allOpen = $this->openNodes[$node];
		}
		return $allOpen;
	}
	
	/**
	 * sets the open flag to FALSE on all of the given nodes
	 *
	 * @param $nodes,... varargs of strings, one of each node to close
	 */
	private function closeNodes($nodes) {
		$nodes = func_get_args();
		foreach ($nodes as $node) {
			$this->openNodes[$node] = FALSE;
		}
	}
	
	/**
	 * sets the open flag on all of the given nodes
	 * @param $node string nodename to set as open
	 * @param $flag TRUE of FALSE  the value to set the open flag to
	 */
	private function openNode($node, $isOpen) {
		$this->openNodes[$node] = $isOpen;
	}
	
	// will not use this because we want to render all functions in 1 file
	public function parseFunction() {}
	
	private function writeTag($tag) {
		if ($this->tagFile) {
		
			// guarantee only 1 newline
			$tag = trim($tag);
			fwrite($this->tagFile, $tag);
			fwrite($this->tagFile, "\n");
		}
	}
}

/*
* vim600: sw=4 ts=4 syntax=php et
* vim<600: sw=4 ts=4
*/
