<?php

/*
 +-----------------------------------------------------------------------+
 | plugins/sieverules/rcube_sieve_script.inc                             |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2008-2009, RoundCube Dev. - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 | PURPOSE:                                                              |
 |	 rcube_sieve_script class for sieverules operations                  |
 |   (using PEAR::Net_Sieve)                                             |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 | Modifications by: Philip Weir                                         |
 |   * Changed name of keys in script array	                             |
 |   * Added support for address and envelope                            |
 |   * Added support for vacation                                        |
 |   * Added support for disabled rules (written to file as comment)     |
 |   * Added support for regex tests                                     |
 |   * Added support for imapflags                                       |
 |   * Added support for relational operators and comparators            |
 |   * Added support for subaddress tests                                |
 |   * Added support for notify action                                   |
 |   * Added support for stop action                                     |
 |   * Added support for body and copy                                   |
 |   * Added support for spamtest (Vladislav Bogdanov)                   |
 +-----------------------------------------------------------------------+

 $Id: $

*/

define('SIEVE_ERROR_BAD_ACTION', 1);
define('SIEVE_ERROR_NOT_FOUND', 2);

// define constants for sieve file
if (!defined('RCUBE_SIEVE_NEWLINE'))
	define('RCUBE_SIEVE_NEWLINE', "\r\n");

if (!defined('RCUBE_SIEVE_INDENT'))
	define('RCUBE_SIEVE_INDENT', "\t");

class rcube_sieve_script {
	private $elsif = true;
	private $content = array();
	private $supported = array(
						'fileinto',
						'reject',
						'ereject',
						'vacation',
						'imapflags',
						'imap4flags',
						'notify',
						'enotify',
						'spamtest',
						);
	public $raw = '';

	public function __construct($script, $ext = array(), $elsif = true) {
		$this->raw = $script;
		$this->elsif = $elsif;

		// adjust supported extenstion to match sieve server
		$this->supported = array_intersect($this->supported, $ext);
		if (in_array('copy', $ext))
			$this->supported = array_merge($this->supported, array('fileinto_copy','redirect_copy'));

		// include standard actions in supported list
		$this->supported = array_merge($this->supported, array('redirect','keep','discard','stop'));

		// load script
		$this->content = $this->parse_text($script);
	}

	public function add_text($script) {
		$content = $this->parse_text($script);
		$result = false;

		// check existsing script rules names
		foreach ($this->content as $idx => $elem)
			$names[$elem['name']] = $idx;

		foreach ($content as $elem) {
			if (!isset($names[$elem['name']])) {
				array_push($this->content, $elem);
				$result = true;
			}
		}

		return $result;
	}

	public function import_filters($content) {
		if (is_array($content)) {
			$result = false;

			// check existsing script rules names
			foreach ($this->content as $idx => $elem)
				$names[$elem['name']] = $idx;

			foreach ($content as $elem) {
				if (!isset($names[$elem['name']])) {
					array_push($this->content, $elem);
					$result = true;
				}
			}
		}
		else {
			$this->add_text($content);
		}
	}

	public function add_rule($content, $pos = null) {
		foreach ($content['actions'] as $action) {
			if (!in_array($action['type'], $this->supported))
				return SIEVE_ERROR_BAD_ACTION;
		}

		if ($pos != null)
			array_splice($this->content, $pos, 0, array($content));
		else
			array_push($this->content, $content);

		return true;
	}

	public function delete_rule($index) {
		if(isset($this->content[$index])) {
			unset($this->content[$index]);
			$this->content = array_values($this->content);
			return true;
		}

		return SIEVE_ERROR_NOT_FOUND;
	}

	public function size() {
		return sizeof($this->content);
	}

	public function update_rule($index, $content) {
		foreach ($content['actions'] as $action) {
			if (!in_array($action['type'], $this->supported))
				return SIEVE_ERROR_BAD_ACTION;
		}

		if ($this->content[$index]) {
			$this->content[$index] = $content;
			return true;
		}

		return SIEVE_ERROR_NOT_FOUND;
	}

	public function move_rule($source, $destination) {
		$this->add_rule($this->content[$source], $destination);

		if ($source < $destination)
			$this->delete_rule($source);
		else
			$this->delete_rule($source + 1);
	}

	public function as_text() {
		$script = '';
		$exts = array();

		// rules
		$activeRules = 0;
		foreach ($this->content as $rule) {
			$tests = array();
			$i = 0;

			if ($rule['disabled'] == 1) {
				$script .= '# rule:[' . $rule['name'] . "]" . RCUBE_SIEVE_NEWLINE;
				$script .= '# disabledRule:[' . $this->_safe_serial(serialize($rule)) . "]" . RCUBE_SIEVE_NEWLINE;
			}
			else {
				// header
				$script .= '# rule:[' . $rule['name'] . "]" . RCUBE_SIEVE_NEWLINE;

				// constraints expressions
				foreach ($rule['tests'] as $test) {
					$tests[$i] = '';

					switch ($test['type']) {
						case 'size':
							$tests[$i] .= ($test['not'] ? 'not ' : '');
							$tests[$i] .= 'size :' . ($test['operator']=='under' ? 'under ' : 'over ') . $test['target'];
							break;
						case 'spamtest':
							array_push($exts, 'spamtest');
							array_push($exts, 'relational');
							array_push($exts, 'comparator-i;ascii-numeric');
							$tests[$i] .= ($test['not'] ? 'not ' : '');
							$tests[$i] .= 'spamtest :value ' . ($test['operator'] == 'eq' ? '"eq" ' :
									($test['operator'] == 'le' ? '"le" ' : '"ge" ')) .
									':comparator "i;ascii-numeric" "' .  $test['target'] .'"';
							break;
						case 'true':
							$tests[$i] .= ($test['not'] ? 'not true' : 'true');
							break;
						case 'exists':
							$tests[$i] .= ($test['not'] ? 'not ' : '');

							if (is_array($test['header']))
								$tests[$i] .= 'exists ["' . implode('", "', $this->_escape_string($test['header'])) . '"]';
							else
								$tests[$i] .= 'exists "' . $this->_escape_string($test['header']) . '"';

							break;
						case 'envelope':
							array_push($exts, 'envelope');
						case 'header':
						case 'address':
							if ($test['operator'] == 'regex')
								array_push($exts, 'regex');
							elseif (substr($test['operator'], 0, 5) == 'count' || substr($test['operator'], 0, 5) == 'value')
								array_push($exts, 'relational');
							elseif ($test['operator'] == 'user' || $test['operator'] == 'detail' || $test['operator'] == 'domain')
								array_push($exts, 'subaddress');

							$tests[$i] .= ($test['not'] ? 'not ' : '');
							$tests[$i] .= $test['type']. ' :' . $test['operator'];

							if ($test['comparator'] != '') {
								if ($test['comparator'] != 'i;ascii-casemap' && $test['comparator'] != 'i;octet')
									array_push($exts, 'comparator-' . $test['comparator']);

								$tests[$i] .= ' :comparator "' . $test['comparator'] . '"';
							}

							if (is_array($test['header']))
								$tests[$i] .= ' ["' . implode('", "', $this->_escape_string($test['header'])) . '"]';
							else
								$tests[$i] .= ' "' . $this->_escape_string($test['header']) . '"';

							if (is_array($test['target']))
								$tests[$i] .= ' ["' . implode('", "', $this->_escape_string($test['target'])) . '"]';
							else
								$tests[$i] .= ' "' . $this->_escape_string($test['target']) . '"';

							break;
						case 'body':
							array_push($exts, 'body');
							if ($test['operator'] == 'regex')
								array_push($exts, 'regex');
							elseif (substr($test['operator'], 0, 5) == 'count' || substr($test['operator'], 0, 5) == 'value')
								array_push($exts, 'relational');

							$tests[$i] .= ($test['not'] ? 'not ' : '');
							$tests[$i] .= $test['type'];

							if ($test['bodypart'] != '')
								$tests[$i] .= ' :' . $test['bodypart'];

							if ($test['contentpart'] != '')
								$tests[$i] .= ' "'. $test['contentpart'] .'"';

							$tests[$i] .= ' :' . $test['operator'];

							if ($test['comparator'] != '') {
								if ($test['comparator'] != 'i;ascii-casemap' && $test['comparator'] != 'i;octet')
									array_push($exts, 'comparator-' . $test['comparator']);

								$tests[$i] .= ' :comparator "' . $test['comparator'] . '"';
							}

							if (is_array($test['target']))
								$tests[$i] .= ' ["' . implode('", "', $this->_escape_string($test['target'])) . '"]';
							else
								$tests[$i] .= ' "' . $this->_escape_string($test['target']) . '"';

							break;
					}

					$i++;
				}

				$script .= ($activeRules > 0 && $this->elsif ? 'els' : '') . ($rule['join'] ? 'if allof (' : 'if anyof (');
				$activeRules++;

				if (sizeof($tests) > 1)
					$script .= implode("," . RCUBE_SIEVE_NEWLINE . RCUBE_SIEVE_INDENT, $tests);
				elseif (sizeof($tests))
					$script .= $tests[0];
				else
					$script .= 'true';


				$script .= ")". RCUBE_SIEVE_NEWLINE ."{" . RCUBE_SIEVE_NEWLINE;

				// action(s)
				$actions = '';
				foreach ($rule['actions'] as $action) {
					switch ($action['type']) {
						case 'fileinto':
							array_push($exts, 'fileinto');
							$actions .= RCUBE_SIEVE_INDENT . "fileinto \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'fileinto_copy':
							array_push($exts, 'fileinto');
							array_push($exts, 'copy');
							$actions .= RCUBE_SIEVE_INDENT . "fileinto :copy \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'redirect':
							$actions .= RCUBE_SIEVE_INDENT . "redirect \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'redirect_copy':
							array_push($exts, 'copy');
							$actions .= RCUBE_SIEVE_INDENT . "redirect :copy \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'reject':
						case 'ereject':
							array_push($exts, $action['type']);

							if (strpos($action['target'], "\n")!==false)
								$actions .= RCUBE_SIEVE_INDENT . $action['type']." text:" . RCUBE_SIEVE_NEWLINE . $action['target'] . RCUBE_SIEVE_NEWLINE . "." . RCUBE_SIEVE_NEWLINE . ";" . RCUBE_SIEVE_NEWLINE;
							else
								$actions .= RCUBE_SIEVE_INDENT . $action['type']." \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;

							break;
						case 'vacation':
							array_push($exts, 'vacation');
							$action['subject'] = $this->_escape_string($action['subject']);
							if ($action['origsubject'] == '1') $action['subject'] .= " \${1}";

// 							// encoding subject header with mb_encode provides better results with asian characters
// 							if (function_exists("mb_encode_mimeheader"))
// 							{
// 								mb_internal_encoding($action['charset']);
// 								$action['subject'] = mb_encode_mimeheader($action['subject'], $action['charset'], 'Q');
// 								mb_internal_encoding(RCMAIL_CHARSET);
// 							}

							$actions .= RCUBE_SIEVE_INDENT . "vacation" . RCUBE_SIEVE_NEWLINE;
							$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":days ". $action['days'] . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['addresses'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":addresses [\"". str_replace(",", "\",\"", $this->_escape_string($action['addresses'])) ."\"]" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['subject'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":subject \"". $action['subject'] ."\"" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['handle'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":handle \"". $this->_escape_string($action['handle']) ."\"" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['from'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":from \"". $this->_escape_string($action['from']) ."\"" . RCUBE_SIEVE_NEWLINE;

							if ($action['charset'] != "UTF-8")
								$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":mime text:". RCUBE_SIEVE_NEWLINE ."Content-Type: text/plain; charset=". $action['charset'] . RCUBE_SIEVE_NEWLINE . RCUBE_SIEVE_NEWLINE . $action['msg'] . RCUBE_SIEVE_NEWLINE . "." . RCUBE_SIEVE_NEWLINE . ";" . RCUBE_SIEVE_NEWLINE;
							elseif (strpos($action['msg'], "\n")!==false)

								$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . "text:" . RCUBE_SIEVE_NEWLINE . $action['msg'] . RCUBE_SIEVE_NEWLINE . "." . RCUBE_SIEVE_NEWLINE . ";" . RCUBE_SIEVE_NEWLINE;
							else
								$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . "\"" . $this->_escape_string($action['msg']) . "\";" . RCUBE_SIEVE_NEWLINE;

							break;
						case 'imapflags':
						case 'imap4flags':
							array_push($exts, $action['type']);

							if (strpos($actions, "setflag") !== false)
								$actions .= RCUBE_SIEVE_INDENT . "addflag \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;
							else
								$actions .= RCUBE_SIEVE_INDENT . "setflag \"" . $this->_escape_string($action['target']) . "\";" . RCUBE_SIEVE_NEWLINE;

							break;
						case 'notify':
							array_push($exts, 'notify');
							$actions .= RCUBE_SIEVE_INDENT . "notify" . RCUBE_SIEVE_NEWLINE;
							$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":method \"" . $this->_escape_string($action['method']) . "\"" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['options'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":options [\"" . str_replace(",", "\",\"", $this->_escape_string($action['options'])) . "\"]" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['from'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":from \"" . $this->_escape_string($action['from']) . "\"" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['importance'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":importance \"" . $this->_escape_string($action['importance']) . "\"" . RCUBE_SIEVE_NEWLINE;
							$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":message \"". $this->_escape_string($action['msg']) ."\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'enotify':
							array_push($exts, 'enotify');
							$actions .= RCUBE_SIEVE_INDENT . "notify" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['options'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":options [\"" . str_replace(",", "\",\"", $this->_escape_string($action['options'])) . "\"]" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['from'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":from \"" . $this->_escape_string($action['from']) . "\"" . RCUBE_SIEVE_NEWLINE;
							if (!empty($action['importance'])) $actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":importance \"" . $this->_escape_string($action['importance']) . "\"" . RCUBE_SIEVE_NEWLINE;
							$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . ":message \"". $this->_escape_string($action['msg']) ."\"" . RCUBE_SIEVE_NEWLINE;
							$actions .= RCUBE_SIEVE_INDENT . RCUBE_SIEVE_INDENT . "\"" . $this->_escape_string($action['method']) . "\";" . RCUBE_SIEVE_NEWLINE;
							break;
						case 'keep':
						case 'discard':
						case 'stop':
							$actions .= RCUBE_SIEVE_INDENT . $action['type'] .";" . RCUBE_SIEVE_NEWLINE;
							break;
					}
				}

				$script .= $actions . "}" . RCUBE_SIEVE_NEWLINE;
			}
		}

		// requires
		$exts = array_unique($exts);
		if (sizeof($exts))
			$script = 'require ["' . implode('","', $exts) . "\"];" . RCUBE_SIEVE_NEWLINE . $script;

		// author
		if ($script)
			$script = "## Generated by RoundCube Webmail SieveRules Plugin ##".  RCUBE_SIEVE_NEWLINE . $script;

		return $script;
	}

	public function as_array() {
		return $this->content;
	}

	public function parse_text($script) {
		$i = 0;
		$content = array();

		// remove C comments
		$script = preg_replace('|/\*.*?\*/|sm', '', $script);

		// tokenize rules - \r is optional for backward compatibility (added 20090413)
		if ($tokens = preg_split('/(# rule:\[.*\])\r?\n/', $script, -1, PREG_SPLIT_DELIM_CAPTURE)) {
			foreach($tokens as $token) {
				if (preg_match('/^# rule:\[(.*)\]/', $token, $matches)) {
					$content[$i]['name'] = $matches[1];
				}
				elseif (isset($content[$i]['name']) && sizeof($content[$i]) == 1 && preg_match('/^# disabledRule:\[(.*)\]/', $token, $matches)) {
					$content[$i] = unserialize($this->_regular_serial($matches[1]));
					$i++;
				}
				elseif (isset($content[$i]['name']) && sizeof($content[$i]) == 1) {
					if ($rule = $this->_tokenize_rule($token)) {
						$content[$i] = array_merge($content[$i], $rule);
						$i++;
					}
					else {
						unset($content[$i]);
					}
				}
			}
		}

		return $content;
	}

	private function _tokenize_rule($content) {
		$result = NULL;

		if (preg_match('/^(if|elsif|else)\s+((true|not\s+true|allof|anyof|exists|header|not|size|envelope|address|spamtest)\s+(.*))\s+\{(.*)\}$/sm', trim($content), $matches)) {
			list($tests, $join) = $this->_parse_tests(trim($matches[2]));
			$actions = $this->_parse_actions(trim($matches[5]));

			if ($tests && $actions) {
				$result = array(
							'tests' => $tests,
							'actions' => $actions,
							'join' => $join,
							);
			}
		}

		return $result;
	}

	private function _parse_actions($content) {
		$content = str_replace("\r\n", "\n", $content);
		$result = NULL;

		// supported actions
		$patterns[] = '^\s*discard;';
		$patterns[] = '^\s*keep;';
		$patterns[] = '^\s*stop;';
		$patterns[] = '^\s*fileinto\s+(:copy\s+)?(.*?[^\\\]);';
		$patterns[] = '^\s*redirect\s+(:copy\s+)?(.*?[^\\\]);';
		$patterns[] = '^\s*setflag\s+(.*?[^\\\]);';
		$patterns[] = '^\s*addflag\s+(.*?[^\\\]);';
		$patterns[] = '^\s*reject\s+text:(.*)\n\.\n;';
		$patterns[] = '^\s*ereject\s+text:(.*)\n\.\n;';
		$patterns[] = '^\s*reject\s+(.*?[^\\\]);';
		$patterns[] = '^\s*ereject\s+(.*?[^\\\]);';
		$patterns[] = '^\s*vacation\s+:days\s+([0-9]+)\s+(:addresses\s+\[(.*?[^\\\])\]\s+)?(:subject\s+(".*?[^"\\\]")\s+)?(:handle\s+(".*?[^"\\\]")\s+)?(:from\s+(".*?[^"\\\]")\s+)?(:mime\s+)?text:(.*)\n\.\n;';
		$patterns[] = '^\s*vacation\s+:days\s+([0-9]+)\s+(:addresses\s+\[(.*?[^\\\])\]\s+)?(:subject\s+(".*?[^"\\\]")\s+)?(:handle\s+(".*?[^"\\\]")\s+)?(:from\s+(".*?[^"\\\]")\s+)?(.*?[^\\\]);';
		$patterns[] = '^\s*notify\s+:method\s+(".*?[^"\\\]")\s+(:options\s+\[(.*?[^\\\])\]\s+)?(:from\s+(".*?[^"\\\]")\s+)?(:importance\s+(".*?[^"\\\]")\s+)?:message\s+(".*?[^"\\\]");';
		$patterns[] = '^\s*notify\s+(:options\s+\[(.*?[^\\\])\]\s+)?(:from\s+(".*?[^"\\\]")\s+)?(:importance\s+(".*?[^"\\\]")\s+)?:message\s+(".*?[^"\\\]")\s+(.*);';

		$pattern = '/(' . implode('$)|(', $patterns) . '$)/ms';

		// parse actions body
		if (preg_match_all($pattern, $content, $mm, PREG_SET_ORDER)) {
			foreach ($mm as $m) {
				$content = trim($m[0]);

				if(preg_match('/^(discard|keep|stop)/', $content, $matches)) {
					$result[] = array('type' => $matches[1]);
				}
				elseif(preg_match('/^fileinto\s+:copy/', $content)) {
					$result[] = array('type' => 'fileinto_copy', 'target' => $this->_parse_string($m[sizeof($m)-1]));
				}
				elseif(preg_match('/^fileinto/', $content)) {
					$result[] = array('type' => 'fileinto', 'target' => $this->_parse_string($m[sizeof($m)-1]));
				}
				elseif(preg_match('/^redirect\s+:copy/', $content)) {
					$result[] = array('type' => 'redirect_copy', 'target' => $this->_parse_string($m[sizeof($m)-1]));
				}
				elseif(preg_match('/^redirect/', $content)) {
					$result[] = array('type' => 'redirect', 'target' => $this->_parse_string($m[sizeof($m)-1]));
				}
				elseif(preg_match('/^(reject|ereject)\s+(.*);$/sm', $content, $matches)) {
					$result[] = array('type' => $matches[1], 'target' => $this->_parse_string($matches[2]));
				}
				elseif(preg_match('/^(setflag|addflag)/', $content)) {
					if (in_array('imap4flags', $this->supported))
						$result[] = array('type' => 'imap4flags', 'target' => $this->_parse_string($m[sizeof($m)-1]));
					else
						$result[] = array('type' => 'imapflags', 'target' => $this->_parse_string($m[sizeof($m)-1]));
				}
				elseif(preg_match('/^vacation\s+:days\s+([0-9]+)\s+(:addresses\s+\[(.*?[^\\\])\]\s+)?(:subject\s+(".*?[^"\\\]")\s+)?(:handle\s+(".*?[^"\\\]")\s+)?(:from\s+(".*?[^"\\\]")\s+)?(.*);$/sm', $content, $matches)) {
					$origsubject = "";
					if (substr($matches[5], -5, 4) == "\${1}") {
						$matches[5] = trim(substr($matches[5], 0, -5)) . "\"";
						$origsubject = "1";
					}

					if (function_exists("mb_decode_mimeheader")) $matches[5] = mb_decode_mimeheader($matches[5]);

					$result[] = array('type' => 'vacation',
									'days' => $matches[1],
									'subject' => $this->_parse_string($matches[5]),
									'origsubject' => $origsubject,
									'from' => $this->_parse_string($matches[9]),
									'addresses' => $this->_parse_string(str_replace("\",\"", ",", $matches[3])),
									'handle' => $this->_parse_string($matches[7]),
									'msg' => $this->_parse_string($matches[10]),
									'charset' => $this->_parse_charset($matches[10]));
				}
				elseif(preg_match('/^notify\s+:method\s+(".*?[^"\\\]")\s+(:options\s+\[(.*?[^\\\])\]\s+)?(:from\s+(".*?[^"\\\]")\s+)?(:importance\s+(".*?[^"\\\]")\s+)?:message\s+(".*?[^"\\\]");$/sm', $content, $matches)) {
					$result[] = array('type' => 'notify',
									'method' => $this->_parse_string($matches[1]),
									'options' => $this->_parse_string($matches[3]),
									'from' => $this->_parse_string($matches[5]),
									'importance' => $this->_parse_string($matches[7]),
									'msg' => $this->_parse_string($matches[8]));
				}
				elseif(preg_match('/^notify\s+(:options\s+\[(.*?[^\\\])\]\s+)?(:from\s+(".*?[^"\\\]")\s+)?(:importance\s+(".*?[^"\\\]")\s+)?:message\s+(".*?[^"\\\]")\s+(.*);$/sm', $content, $matches)) {
					$result[] = array('type' => 'enotify',
									'method' => $this->_parse_string($matches[8]),
									'options' => $this->_parse_string($matches[2]),
									'from' => $this->_parse_string($matches[4]),
									'importance' => $this->_parse_string($matches[6]),
									'msg' => $this->_parse_string($matches[7]));
				}
			}
		}

		return $result;
	}

	private function _parse_tests($content) {
		$result = NULL;

		// lists
		if (preg_match('/^(allof|anyof)\s+\((.*)\)$/sm', $content, $matches)) {
			$content = $matches[2];
			$join = $matches[1]=='allof' ? true : false;
		}
		else {
			$join = false;
		}

		// supported tests regular expressions
		$patterns[] = '(not\s+)?(exists)\s+\[(.*?[^\\\])\]';
		$patterns[] = '(not\s+)?(exists)\s+(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(true)';
		$patterns[] = '(not\s+)?(size)\s+:(under|over)\s+([0-9]+[KGM]{0,1})';
		$patterns[] = '(not\s+)?(spamtest)\s+:value\s+"(eq|ge|le)"\s+:comparator\s+"i;ascii-numeric"\s+"(.*?[^\\\])"';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(contains|is|matches|regex|user|detail|domain)((\s+))\[(.*?[^\\\]")\]\s+\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(contains|is|matches|regex|user|detail|domain)((\s+))(".*?[^\\\]")\s+(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(contains|is|matches|regex|user|detail|domain)((\s+))\[(.*?[^\\\]")\]\s+(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(contains|is|matches|regex|user|detail|domain)((\s+))(".*?[^\\\]")\s+\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+\[(.*?[^\\\]")\]\s+\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+(".*?[^\\\]")\s+(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+\[(.*?[^\\\]")\]\s+(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(header|address|envelope)\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+(".*?[^\\\]")\s+\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(body)(\s+:(raw|text|content\s+".*?[^\\\]"))?\s+:(contains|is|matches|regex)((\s+))\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(body)(\s+:(raw|text|content\s+".*?[^\\\]"))?\s+:(contains|is|matches|regex)((\s+))(".*?[^\\\]")';
		$patterns[] = '(not\s+)?(body)(\s+:(raw|text|content\s+".*?[^\\\]"))?\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+\[(.*?[^\\\]")\]';
		$patterns[] = '(not\s+)?(body)(\s+:(raw|text|content\s+".*?[^\\\]"))?\s+:(count\s+".*?[^\\\]"|value\s+".*?[^\\\]")(\s+:comparator\s+"(.*?[^\\\])")?\s+(".*?[^\\\]")';

		// join patterns...
		$pattern = '/(' . implode(')|(', $patterns) . ')/';

		// ...and parse tests list
		if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$size = sizeof($match);

				if (preg_match('/^(not\s+)?size/', $match[0])) {
					$result[] = array(
									'type' 		=> 'size',
									'not' 		=> $match[$size-4] ? true : false,
									'operator' 	=> $match[$size-2], // under/over
									'target'	=> $match[$size-1], // value
								);
				}
				elseif (preg_match('/^(not\s+)?spamtest/', $match[0])) {
					$result[] = array(
									'type' 		=> 'spamtest',
									'not' 		=> $match[$size-4] ? true : false,
									'operator' 	=> $match[$size-2], // ge/le/eq
									'target'	=> $match[$size-1], // value
								);
				}
				elseif (preg_match('/^(not\s+)?(header|address|envelope)/', $match[0])) {
					$result[] = array(
									'type'		=> $match[$size-6],
									'not' 		=> $match[$size-7] ? true : false,
									'operator'	=> $match[$size-5], // is/contains/matches
									'header' 	=> $this->_parse_list($match[$size-2]), // header(s)
									'target'	=> $this->_parse_list($match[$size-1]), // string(s)
									'comparator' => trim($match[$size-3])
								);
				}
				elseif (preg_match('/^(not\s+)?exists/', $match[0])) {
					$result[] = array(
									'type'	 	=> 'exists',
									'not' 		=> $match[$size-3] ? true : false,
									'operator'	=> 'exists',
									'header' 	=> $this->_parse_list($match[$size-1]), // header(s)
								);
				}
				elseif (preg_match('/^(not\s+)?true/', $match[0])) {
					$result[] = array(
									'type' 	=> 'true',
									'not' 	=> $match[$size-2] ? true : false,
								);
				}
				elseif (preg_match('/^(not\s+)?body/', $match[0])) {
					if (preg_match('/.*content\s+"(.*?[^\\\])".*/', $match[$size-5], $parts)) {
						$bodypart = 'content';
						$contentpart = $parts[1];
					}
					else {
						$bodypart = $match[$size-5];
						$contentpart = '';
					}

					$result[] = array(
									'type'		=> 'body',
									'not' 		=> $match[$size-8] ? true : false,
									'bodypart'	=> $bodypart,
									'contentpart' => $contentpart,
									'operator'	=> $match[$size-4], // is/contains/matches
									'header' 	=> 'body', // header(s)
									'target'	=> $this->_parse_list($match[$size-1]), // string(s)
									'comparator' => trim($match[$size-2])
								);
				}
			}
		}

		return array($result, $join);
	}

	private function _parse_string($content) {
		$text = '';
		$content = trim($content);

		if (preg_match('/^:mime\s+text:(.*)\.$/sm', $content, $matches)) {
			$parts = explode("\r?\n", $matches[1], 4);
			$text = trim($parts[3]);
		}
		elseif (preg_match('/^text:(.*)\.$/sm', $content, $matches))
			$text = trim($matches[1]);
		elseif (preg_match('/^"(.*)"$/', $content, $matches))
			$text = str_replace('\"', '"', $matches[1]);

		return $text;
	}

	private function _parse_charset($content) {
		$charset = RCMAIL_CHARSET;
		$content = trim($content);

		if (preg_match('/^:mime\s+text:(.*)\.$/sm', $content, $matches)) {
			$parts = explode("\r?\n", $matches[1], 4);
			$charset = trim(substr($parts[1], stripos($parts[1], "charset=") + 8));
		}

		return $charset;
	}

	private function _escape_string($content) {
		$replace['/"/'] = '\\"';

		if (is_array($content)) {
			for ($x=0, $y=sizeof($content); $x<$y; $x++)
				$content[$x] = preg_replace(array_keys($replace), array_values($replace), $content[$x]);

			return $content;
		}
		else {
			return preg_replace(array_keys($replace), array_values($replace), $content);
		}
	}

	private function _parse_list($content) {
		$result = array();

		for ($x=0, $len=strlen($content); $x<$len; $x++) {
			switch ($content[$x]) {
				case '\\':
					$str .= $content[++$x];
					break;
				case '"':
					if (isset($str)) {
						$result[] = $str;
						unset($str);
					}
					else {
						$str = '';
					}

					break;
				default:
					if(isset($str))
						$str .= $content[$x];

					break;
			}
		}

		if (sizeof($result)>1)
			return $result;
		elseif (sizeof($result) == 1)
			return $result[0];
		else
			return NULL;
	}

	private function _safe_serial($data) {
		$data = str_replace("\r", "[!r]", $data);
		$data = str_replace("\n", "[!n]", $data);
		return $data;
	}

	private function _regular_serial($data) {
		$data = str_replace("[!r]", "\r", $data);
		$data = str_replace("[!n]", "\n", $data);
		return $data;
	}
}

?>
