<?php
namespace CommentMarkdown;

function parse($raw, $postid) {
	return documentFromLines( linesFromText($raw), $postid );
}

function linesFromText($md) {
	$lines = array();
	foreach(explode("\n",$md) as $rawline) {
		if(ord($rawline[strlen($rawline) - 1]) == 13) $rawline = substr($rawline,0,-1);
		if(trim($rawline) == '') {
			// blank line
			$lines[] = array('type'=>'blank');
		}
		else if(preg_match("/^( (\*\s*){3,} | (-\s*){3,} | (_\s*){3,} )$/x", trim($rawline))) {
			// <hr>
			$lines[] = array('type'=>'hr', 'raw'=>$rawline);
		}
		else if(preg_match("/^\d+\.\s+(.+)/", $rawline, $matches)) {
			// Start of a numbered list item
			$lines[] = array('type'=>'numbered', 'text'=>$matches[1], 'raw'=>$rawline);
		}
		else if(preg_match("/^[-*+]\s+(.+)/", $rawline, $matches)) {
			// Start of a bulleted list item
			$lines[] = array('type'=>'bulleted', 'text'=>$matches[1], 'raw'=>$rawline);
		}
		else if(preg_match("/^> (.*)/", $rawline, $matches)) {
			// Blockquote
			$lines[] = array('type'=>'quote', 'text'=>$matches[1], 'raw'=>$rawline);
		}
		else if(preg_match("/^[ ]{0,3} \[([^\]]+)\] : \s* (\S+) \s* (?| \"([^\"]+)\" | '([^']+)' | \(([^)]+)\) | ) \s*$/x", $rawline, $matches)) {
			// Link reference
			$lines[] = array('type'=>'ref', 'ref'=>$matches[1], 'link'=>$matches[2], 'title'=>$matches[3], 'raw'=>$rawline);
		}
		else if(preg_match("/^Re #(\d+):\s*(.*)$/", $rawline, $matches)) {
			// Comment link
			$lines[] = array('type'=>'reply', 'reply-to'=>intval($matches[1]), 'text'=>$matches[2], 'raw'=>$rawline);
		}
		else if(preg_match("/^~~~~(.*)/", $rawline, $matches)) {
			// Explicit code delimiter
			$lines[] = array('type'=>'code', 'data'=>$matches[1], 'raw'=>$rawline);
		}
		else {
			// Normal line of text.
			preg_match("/^(\s*)(.*)/", $rawline, $matches);
			$lines[] = array('type'=>'text', 'text'=>$matches[2], 'spaces'=>strlen($matches[1]), 'raw'=>$rawline);
		}
	}
	return $lines;
}

function documentFromLines($lines, $postid='') {
	$state = "start";
	$lines[] = array('eof');
	$doc = new Document;
	$currelem = null;
	for($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];
		$type = $line['type'];
		$nextline = $lines[$i+1];
		$nexttype = $nextline['type'];

		if($state == "start") {
			$currelem = null;
			if($type == 'blank') {
				// Do nothing.
			} else if($type == 'hr') {
				$doc->append(new Separator);
			} else if($type == 'code') {
				$currelem = new Code;
				$state = 'explicit-code';
			} else if($type == 'text' && $line['spaces'] >= 4) {
				$currelem = new Code(substr($line['raw'], 4));
				$state = 'indented-code';
			} else if($type == 'ref') {
				LinkRefs::set($line['ref'], $line['link'], $line['title']);
			} else if($type == 'bulleted') {
				$currelem = new BulletedList($line['text']);
				$state = 'bulleted-list';
			} else if($type == 'numbered') {
				$currelem = new NumberedList($line['text']);
				$state = 'numbered-list';
			} else if($type == 'quote') {
				$currelem = new Quote($lines['text']);
				$state = 'quote';
			} else if($type == 'reply') {
				$currelem = new Paragraph($line['text']);
				$currelem->replyId = $postid . '-' . $line['reply-to'];
				$currelem->replyTo = $line['reply-to'];
				$state = 'paragraph';
			} else if($type == 'text') {
				$currelem = new Paragraph($line['text']);
				$state = 'paragraph';
			}
		} else if($state == 'explicit-code') {
			if($type == 'code') {
				$doc->append($currelem);
				$state = 'start';
			} else if($type == 'eof') {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			} else {
				$currelem->append($line['raw']);
			}
		} else if($state == 'indented-code') {
			if($type == 'text' && $line['spaces'] >= 4) {
				$currelem->append(substr($line['raw'], 4));
			} else {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			}
		} else if($state == 'bulleted-list') {
			if($type == 'text') {
				$currelem->append($line['text']);
			} else if($type == 'bulleted') {
				$currelem->newItem($line['text']);
			} else if($type == 'blank' && $nexttype == 'bulleted') {
				$currelem->compact = false;
			} else if($type == 'blank' && $nexttype == 'text' && $nextline['spaces'] >= 4) {
				$currelem->compact = false;
				$currelem->append('');
			} else {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			}
		} else if($state == 'numbered-list') {
			if($type == 'text') {
				$currelem->append($line['text']);
			} else if($type == 'numbered') {
				$currelem->newItem($line['text']);
			} else if($type == 'blank' && $nexttype == 'numbered') {
				$currelem->compact = false;
			} else if($type == 'blank' && $nexttype == 'text' && $nextline['spaces'] >= 4) {
				$currelem->compact = false;
				$currelem->append('');
			} else {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			}
		} else if($state == 'quote') {
			if($type == 'quote') {
				$currelem->append($line['text']);
			} else {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			}
		} else if($state == 'paragraph') {
			if($type == 'text') {
				$currelem->append($line['text']);
			} else {
				$i--;
				$doc->append($currelem);
				$state = 'start';
			}
		}
	}
	return $doc->finish();
}

function parseInlines($raw) {
	/*	Inlines can be split into "atomic" and "nestable".
		Atomics are things like `code` and [text](link), which can't contain any other markup inside of themselves.
		Nestables are things like *italics* and **bold**, because they can have more markup inside.
		You have to deal with atomics first, because otherwise you'll screw up and get a nestable ending inside of an atomic.
		So, I first remove the atomics, replacing each occurrence with a NUL U+0000 character.
		(I strip out literal nulls from the original text,
		because the HTML parser will just replace them anyway.)
		Then I process the nestable ones,
		and then restore the atomics.
	*/

	list($text, $subs) = removeAtomics($raw);

	$text = processNestables($text);

	$text = restoreAtomics($text, $subs);

	return $text;
}

function processNestables($raw) {
	$text = '';
	for($i = 0; $i < strlen($raw); $i++) {
		$char = $raw[$i];

		if( $char == '*' && preg_match('/^\*{3}([\w\00](.*[\w\00])?)\*{3}[^*\w\00]/', substr($raw, $i), $matches) ) {
			// Bolditalic with *
			$text .= '<b><i>' . processNestables($matches[1]) . '</i></b>';
			$i += strlen($matches[1]) + 5;
		} else if( $char == '_' && preg_match('/^_{3}([\a-zA-Z0-9\00](.*[\a-zA-Z0-9\00])?)_{3}[^\w\00]/', substr($raw, $i), $matches) ) {
			// Bolditalic with _
			$text .= '<b><i>' . processNestables($matches[1]) . '</i></b>';
			$i += strlen($matches[1]) + 5;
		} else if( $char == '*' && preg_match('/^\*{2}([\w\00](.*[\w\00])?)\*{2}[^*\w\00]/', substr($raw, $i), $matches) ) {
			// Bold with *
			$text .= '<b>' . processNestables($matches[1]) . '</b>';
			$i += strlen($matches[1]) + 3;
		} else if( $char == '_' && preg_match('/^_{2}([\a-zA-Z0-9\00](.*[\a-zA-Z0-9\00])?)_{2}[^\w\00]/', substr($raw, $i), $matches) ) {
			// Bold with _
			$text .= '<b>' . processNestables($matches[1]) . '</b>';
			$i += strlen($matches[1]) + 3;
		} else if( $char == '*' && preg_match('/^\*([\w\00](.*[\w\00])?)\*[^*\w\00]/', substr($raw, $i), $matches) ) {
			// Italic with *
			$text .= '<i>' . processNestables($matches[1]) . '</i>';
			$i += strlen($matches[1]) + 1;
		} else if( $char == '_' && preg_match('/^_([\a-zA-Z0-9\00](.*[\a-zA-Z0-9\00])?)_[^\w\00]/', substr($raw, $i), $matches) ) {
			// Italic with _
			$text .= '<i>' . processNestables($matches[1]) . '</i>';
			$i += strlen($matches[1]) + 3;
		} else {
			$text .= $char;
		}
	}
	return $text;
}

function removeAtomics($raw) {
	$text = '';
	$nul = chr(0);
	$subs = array();
	for($i = 0; $i < strlen($raw); $i++) {
		$char = $raw[$i];

		if( $char == '`' && preg_match('/^`\s?([^`]+?)\s?`/', substr($raw, $i), $matches) ) {
			// Code literal: `code here`
			$text .= $nul;
			$subs[] = '<code>' . htmlspecialchars($matches[1]) . '</code>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '`' && preg_match('/^``\s?(.+?)\s?``/', substr($raw, $i), $matches) ) {
			// Double-ticked code literal: `code here`
			$text .= $nul;
			$subs[] = '<code>' . htmlspecialchars($matches[1]) . '</code>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '!' && preg_match('/^! \[ ([^\]]+) \] \( ([^\s)]+)(\s+[^)]*|) \)/x', substr($raw, $i), $matches) ) {
			// Image: ![alt](link title)
			$text .= $nul;
			$subs[] = '<img src="' . escapeAttr($matches[2]) . '" alt="' . escapeAttr($matches[1]) . '" title="' . escapeAttr($matches[3]) . '">';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '!' && preg_match('/^! \[ ([^\]]+) \]\[ ([^\]]+) \]/x', substr($raw, $i), $matches) && LinkRefs::has($matches[2]) ) {
			// Referenced image: ![alt][ref]
			$ref = LinkRefs::get($matches[2]);
			$text .= $nul;
			$subs[] = '<img src="' . escapeAttr($ref['link']) . '" alt="' . escapeAttr($matches[1]) . '" title="' . escapeAttr($ref['title']) . '">';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '[' && preg_match('/^\[ ([^\]]+) \] \( ([^\s)]+)(\s+[^)]* |) \)/x', substr($raw, $i), $matches) ) {
			// Link: [text](link title)
			$text .= $nul;
			$subs[] = '<a href="' . escapeAttr($matches[2]) . '" title="' . escapeAttr($matches[3]) . '">' . htmlspecialchars($matches[1]) . '</a>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '[' && preg_match('/^\[ ([^\]]+) \]\[ ([^\]]+) \]/x', substr($raw, $i), $matches) /*&& LinkRefs::has($matches[2])*/ ) {
			// Reference Link: [text][ref]
			$ref = LinkRefs::get($matches[2]);
			$text .= $nul;
			$subs[] = '<a href="' . escapeAttr($ref['link']) . '" title="' . escapeAttr($ref['title']) . '">' . htmlspecialchars($matches[1]) . '</a>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '[' && preg_match('/^\[ ([^\]]+) \]\[\]/x', substr($raw, $i), $matches) && LinkRefs::has($matches[1]) ) {
			// Implicit Reference Link: [ref][]
			$ref = LinkRefs::get($matches[1]);
			$text .= $nul;
			$subs[] = '<a href="' . escapeAttr($ref['link']) . '" title="' . escapeAttr($ref['title']) . '">' . htmlspecialchars($matches[1]) . '</a>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '<' && preg_match('/^<(\w+:[^>]+)>/', substr($raw, $i), $matches) ) {
			// Literal link: <link>
			$text .= $nul;
			$subs[] = '<a href="' . escapeAttr($matches[1]) . '">' . htmlspecialchars($matches[1]) . '</a>';
			$i += strlen($matches[0]) - 1;
		} else if( $char == '<' && preg_match('/^<(\S+@\S+)>/', substr($raw, $i), $matches) ) {
			// Literal email address: <link>
			$text .= $nul;
			$subs[] = '<a href="mailto:' . escapeAttr($matches[1]) . '">' . htmlspecialchars($matches[1]) . '</a>';
			$i += strlen($matches[0]) - 1;
		} else if($char == '<') {
			// All other occurences of <
			$text .= '&lt;';
		} else if($char === '&' && preg_match('/^& ( [a-zA-Z]+ | \#[0-9]+ | \#x[0-9a-fA-F]+ ) ;/x', substr($raw, $i), $matches) ) {
			// Character reference: &copy;, etc.
			$text .= $matches[0];
			$i += strlen($matches[0]) - 1;
		} else if($char == '&') {
			// All other occurences of &
			$text .= '&amp;';
		} else if( $char == '\\' && preg_match('/^\\\\([\\\\`*_{}[\]()#+.!-])' . '/', substr($raw, $i), $matches) ) {
			// Character escape
			$text .= $nul;
			$subs[] = $matches[0];
			$i++;
		} else if( $char == '\\' && $i == 0 && preg_match('/^\\\\(\d)/', $raw, $matches) ) {
			// Escaped digit at the start of a line (to suppress it being interpreted as a numbered list)
			$text .= $matches[1];
			$i++;
		} else if( $char == chr(0) ) {
			// Literal nul in the original text.
			// Do nothing.
		} else {
			$text .= $char;
		}
	}

	return array($text, $subs);
}

function restoreAtomics($raw, $subs) {
	$text = '';
	$nul = chr(0);
	for($i = 0; $i < strlen($raw); $i++) {
		$char = $raw[$i];
		if( $char == $nul ) {
			$text .= array_shift($subs);
		} else {
			$text .= $char;
		}
	}
	return $text;
}

function escapeAttr($text) {
	return str_replace('"', '&quot;', $text);
}


class LinkRefs {
	public static $linkrefs = array();

	public static function set($ref, $link, $title='') {
		self::$linkrefs[strtolower($ref)] = array('link'=>$link, 'title'=>$title);
	}
	public static function has($ref) {
		return isset( self::$linkrefs[strtolower($ref)] );
	}
	public static function get($ref) {
		//if( self::has($ref) ) {
			return self::$linkrefs[strtolower($ref)];
		//}
	}
}



class Element {
	protected $finished = false;

	function finish() {
		$this->finished = true;
		return $this;
	}

	function __toString() {
		return $this->toHTML();
	}
}

class Document extends Element {
	public $elements = array();

	function __construct($elements = array()) {
		$this->elements = $elements;
	}

	function append($elem) {
		$this->elements[] = $elem;
		return $this;
	}

	function finish() {
		foreach($this->elements as $element) $element->finish();
		$this->finished = true;
		return $this;
	}

	function toHTML() {
		return implode( array_map(function($x) { return $x->toHTML(); }, $this->elements) );
	}
}

class Separator extends Element {
	function __construct() {}

	function toHTML() {
		return "<hr>";
	}
}

class Code extends Element {
	public $raw;
	protected $lines = array();

	function __construct($firstLine = null) {
		if($firstLine) { $this->lines[] = $firstLine; }
	}

	function append($line) {
		$this->lines[] = $line;
		return $this;
	}

	function finish() {
		$this->finished = true;
		$this->text = htmlspecialchars(implode("\n",$this->lines));
		return $this;
	}

	function toHTML() {
		return "<pre class='code'>" . $this->text . "</pre>";
	}
}

class Paragraph extends Element {
	public $raw;
	public $replyId;
	public $replyTo;
	protected $lines = array();

	function __construct($firstLine = null) {
		if($firstLine) { $this->lines[] = $firstLine; }
	}

	function append($line) {
		$this->lines[] = $line;
		return $this;
	}

	function finish() {
		$finished = true;
		if( is_int($this->replyTo) ) {
			$this->text .= "Re <a href='#" . $this->replyId . "'>#" . $this->replyTo . "</a>: ";
		}
		foreach($this->lines as $i=>$line) {
			$this->text .= parseInlines($line);
			if( $i != count($this->lines) - 1 )
				$this->text .= ' ';
			if(preg_match("/\s{2}$/", $line))
				$this->text .= "<br>";
		}
		return $this;
	}

	function toHTML() {
		return "<p>" . $this->text;
	}
}

class Quote extends Element {
	public $raw;
	protected $lines = array();

	function __construct($firstLine = null) {
		if($firstLine) { $this->lines[] = $firstLine; }
	}

	function append($line) {
		$this->lines[] = $line;
		return $this;
	}

	function finish() {
		$finished = true;
		foreach($this->lines as $i=>$line) {
			$this->text .= parseInlines($line);
			if( $i != count($this->lines) - 1 )
				$this->text .= ' ';
			if(preg_match("/\s{2}$/", $line))
				$this->text .= "<br>";
		}
		return $this;
	}

	function toHTML() {
		return "<blockquote>" . $this->text . "</blockquote>";
	}
}

class MarkdownList extends Element {
	public $items;
	public $compact = true;
	protected $unfinisheditems = array();

	function __construct($firstLine = null) {
		if($firstLine) {
			$this->unfinisheditems[] = array($firstLine);
		}
	}

	function append($line) {
		if(count($this->unfinisheditems) == 0) $unfinisheditems[0] = array();
		$this->unfinisheditems[count($this->unfinisheditems) - 1][] = $line;
		return $this;
	}

	function newItem($line) {
		$this->unfinisheditems[] = array();
		return $this->append($line);
	}

	function finish() {
		$this->finished = true;
		foreach($this->unfinisheditems as $unfinisheditem) {
			$item = '';
			foreach($unfinisheditem as $i=>$line) {
				if($i == 0 && !$compact)
					$item .= "<p>";
				if($line == '')
					$item .= "<p>";
				else {
					$item .= parseInlines($line);
					if( $i != count($unfinisheditem) - 1 )
						$this->text .= ' ';
					if(preg_match("/\s{2}$/", $line))
						$item .= "<br>";
				}
			}
			$this->items[] = $item;
		}
		return $this;
	}
}

class BulletedList extends MarkdownList {
	function __construct($firstLine = null) {
		parent::__construct($firstLine);
	}

	function toHTML() {
		$raw = "<ul>";
		foreach($this->items as $item) {
			$raw .= "<li>" . $item;
		}
		$raw .= "</ul>";
		return $raw;
	}
}

class NumberedList extends MarkdownList {
	function __construct($firstLine = null) {
		parent::__construct($firstLine);
	}

	function toHTML() {
		$raw = "<ol>";
		foreach($this->items as $item) {
			$raw .= "<li>" . $item;
		}
		$raw .= "</ol>";
		return $raw;
	}
}