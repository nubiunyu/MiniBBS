<?php
/* The parser and related methods. Accessed statically. */
class parser {
	/* Wiki-style mark-up and BBCode */
	private static $markup = array 
	(
		// 01 Bold.
		'/\[b\](.*?)\[\/b\]/is',
		"/'''(.+?)'''/",
		// 02 Italic.
		'/\[i\](.*?)\[\/i\]/is',
		"/''(.+?)''/",
		// 03 Spoiler.
		'/\[spoiler\](.*?)\[\/spoiler\]/is',
		'/\*\*(.*?)\*\*/is',
		// 04 Underline.
		'/\[u\](.*?)\[\/u\]/is',
		// 05 Strikethrough.
		'/\[s\](.*?)\[\/s\]/is',
		// 06 Linkify URLs.
		'@\b(?<!\[)(https?|ftp)://(www\.)?([A-Z0-9.-]+)(/)?([A-Z0-9/&#+%~=_|?.,!:;-]*[A-Z0-9/&#+%=~_|])?@i',
		// 07 Linkify text in the form of [http://example.org text].
		'@\[(https?:\/\/[\@a-z0-9\x21\x23-\x27\x2a-\x2e\x3a\x3b\/;\x3f-\x7a\x7e\x3d]+) (.+?)\]@i',
		// 08 Quotes.
		'/^&gt;(.*)$/m',
		// 09 Headers.
		'/\[h\](.+?)\[\/h\]/m',
		'/==(.+?)==\s+?/m',
		// 10 Bordered text.
		'/\[border\](.+?)\[\/border\]/ms',
		// 11 Convert double dash in to a —.
		'/--/',
		// 12 Highlights.
		'/\[hl\](.+?)\[\/hl\]/ms',
		// 13 Monospace.
		'/\[code\](.+?)\[\/code\]/ms',
		// 14 Shift-JIS
		'/\[aa\](.+?)\[\/aa\]/ms'
	);
	
	/* HTML to replace the $markup */
	private static $replacements = array (
		'<strong>$1</strong>', #01
		'<strong>$1</strong>', #01
		'<em>$1</em>', #02
		'<em>$1</em>', #02
		'<span class="spoiler">$1</span>', #03
		'<span class="spoiler">$1</span>', #03
		'<u>$1</u>', #04
		'<s>$1</s>', #05
		'<a href="$0" rel="nofollow">$0</a>', #06
		'<a href="$1" title="$1" rel="nofollow">$2</a>', #07
		'<span class="quote"><strong>&gt;</strong> $1</span>', #08
		'<h4 class="user">$1</h4>', #09
		'<h4 class="user">$1</h4>', #09
		'<div class="border">$1</div>', #10
		'—', #11
		'<span class="highlight">$1</span>', #12
		'<pre style="display:inline">$1</pre>', #13
		'<pre class="shift_jis">$1</pre>' #14
	);

	/* Converts user input to HTML */
	public static function parse($text, $uid = null) {
		$text = htmlspecialchars($text);
		$text = str_replace("\r", '', $text);
		
		/* Temporarily remove content between [noparse] tags before parsing the rest */
		$noparse_offset = 0;
		while( ($noparse_start = strpos($text, '[noparse]', $noparse_offset)) !== false) {
			/* [noparse] tag has 9 characters -- we leave it but replace the content */
			$noparse_start += 9;
			$noparse_offset = $noparse_start;
			$noparse_end = strpos($text, '[/noparse]', $noparse_offset);
			
			if($noparse_end) {
				$noparse_inner = substr($text, $noparse_start, $noparse_end - $noparse_start);
				/* Prevent citations from being parsed. */
				$noparse_inner = str_replace('@', '&#64;', $noparse_inner);
				$noparse_blocks[] = $noparse_inner;
				$text = substr_replace($text, '', $noparse_start, $noparse_end - $noparse_start);
			}
			
		}
		
		/* Replace mark-up with HTML */
		$text = preg_replace(self::$markup, self::$replacements, $text);
		
		/* Parse user signatures (~~~~) */
		if(SIGNATURES && isset($uid) && strpos($text, '~~~~') !== false) {
			$hash = sha1($uid . TRIP_SEED);
			
			/* Get 4 sets of 6 hex characters for colors */
			$colors = str_split(substr($hash, 0, 24), 6);
			
			/* Get 4 sets of 2 digits for lengths */
			$percents = array_map('hexdec', str_split(substr($hash, 0, 8), 2));
			/* Convert to percentage */
			$weight = 100 / array_sum($percents);
			foreach($percents as $key => $percent) {
				$percents[$key] = $percent * $weight;
			}
			
			$signature = '<span class="signature help" title="'.$hash.'">';
			foreach($colors as $key => $color) {
				$signature .= '<span class="signature_part" style="width:'.$percents[$key].'%; background-color:#'.$color.';"></span>';
			}
			$signature .= '</span>';
			
			$text = str_replace('~~~~', $signature, $text);
		}
		
		/* Parse PHP tags */
		if(strpos($text, '[php]') !== false) {
			$text = preg_replace_callback('|\[php\](.+?)\[/php\]|ms', array('self', 'highlight_php'), $text);
		}
		
		/* Parse tables */
		if(strpos($text, "\n|") !== false) {
			$text = self::table($text);
		}
		
		/* Add [play] links for streaming videos */
		if (EMBED_VIDEOS) {
			if (strpos($text, 'youtube.com') !== false) {
				$text = preg_replace ( "/(<a href=\"https?:\/\/(www\.)?youtube\.com\/watch\?([&;a-zA-Z0-9=_]+)?v=([^',\.& \t\r\n\v\f]+)([^',\. \t\r\n\v\f]+)?\"([^<]*)*<\/a>)/", "\\1 [<a href=\"javascript:void(0);\" onclick=\"play_video('youtube','\\4', this, '$record_class', '$record_ID');\" class=\"video youtube\">play</a>]", $text);
			}
			if (strpos($text, 'vimeo.com') !== false) {
				$text = preg_replace("/(<a href=\"http:\/\/(www\.)?vimeo\.com\/([0-9]+)([^',\. \t\r\n\v\f]+)?\"([^<]*)*<\/a>)/", "\\1 [<a href=\"javascript:void(0);\" onclick=\"play_video('vimeo','\\3', this, '$record_class', '$record_ID');\" class=\"video vimeo\">play</a>]", $text);
			}
		}

		/* Restore [noparse] content */
		if( ! empty($noparse_blocks)) {
			$chunks = explode('[noparse][/noparse]', $text);
			$text = '';
			
			foreach($chunks as $key => $chunk) {
				$text .= $chunk;
				if(isset($noparse_blocks[$key])) {
					$text .= $noparse_blocks[$key];
				}
			}
		}
		
		$text = nl2br($text);
				
		/* If any <pre> tags are used, fix the double-spacing. */
		if(strpos($text, '<pre') !== false) {
			$text = preg_replace_callback('|\<pre(.+?)\</pre\>|s', array('self', 'fix_pre_tags'), $text);
		}
		return $text;
	}	

	/* Callback: Removes unnecessary HTML linebreaks from <pre> tags */
	private static function fix_pre_tags($matches) {
		return str_replace('<br />', '', $matches[0]);
	}

	/* Callback: Highlights code between [php] tags */
	private static function highlight_php($matches) {
		/* highlight_string won't do anything without a <?php tag */
		$text = '<?php ' . trim($matches[1], "\r\n");
		/* highlight_string also escapes HTML characters and indents, so no <pre> or htmlspecialchars is needed */
		$text = highlight_string(html_entity_decode($text), true);
		/* Remove the <?php we added */
		$text = preg_replace('/<span style="color: #([A-Z0-9]+)">&lt;\?php(&nbsp;| )/i', '<span style="color: #$1">', $text);
		/* Remove ugly opening and closing linebreaks */
		$text = substr_replace($text, '', strpos($text, "\n"), 1);
		$text = substr_replace($text, '', strrpos($text, "\n"), 1);
		
		return '<div class="php">' . $text . '</div>';
	}
	
	/**
	 * Transforms table mark-up into HTML:
	 * ||     header cell (<th>)
	 * ||!    main header cell (other columns will be shrunk with class="minimal")
	 * |      normal cell
	 */
	private static function table($post, $recurse_level = 0) {
		/* Will be populated by <th> cells (||). */
		$columns = array();
		/* An array of ints identifying stretched columns (||!) */
		$main_columns = array();
		/* Will be populated by <td> cells (|). */
		$rows = array();
		
		/* Split post at the beginning of the table. */
		$delim = "\n|";
		if($post[0] === '|') {
			/* The table starts the post; there is no \n */
			$delim = '|';
		}
		list($before_table, $remainder) = explode($delim, $post, 2);
		$after_table = '';
		
		/* Restore the table opener removed by explode. */
		$remainder = '|' . $remainder;
		
		$table_lines = explode("\n", $remainder);
		
		foreach($table_lines as $row => $line) {
				
			if($line[0] !== '|') {
				/* Our table ends here; save the post-table content for later. */
				array_splice($table_lines, 0, $row);
				$after_table = implode("\n", $table_lines);
				break;
			}
			
			if($row === 0 && $line[1] === '|') {
				/* This is a header row; it begins with ||. */
				$columns = explode('||', $line);
				/* Remove the blank element from the beginning of the line */
				array_shift($columns);
				
				foreach($columns as $key => $column) {
					if($column[0] === '!') {
						/* This is a primary column; it begins with ||! */
						$columns[$key] = ltrim($column, '!');
						$main_columns[] = $key;
					}
				}
			} else {
				/* This is a normal row; it begins with |. */
				$cells = explode('|', $line);
				array_shift($cells);
				$rows[$row] = $cells;
			}
			
		}
		
		/* Pass the arrays onto our table class. 700 is an arbitrary limit to prevent abuse. */
		if( ! empty($rows) && count($rows) < 700) {
			$table = new Table($columns, $main_columns, (empty($main_columns) ? 'minimal_table' : null));
			
			foreach($rows as $row) {
				$table->row($row);
			}
			
			/* Remove linebreaks inserted by the table class so they won't be transformed into <br /> */
			$table = str_replace(array("\r", "\n"), '', $table);
			$post = $before_table . $table . $after_table;
			
			/* Parse any remaining tables -- up to 7, another arbitrary limit. */
			if($recurse_level < 6 && strpos($post, "\n|") !== false) {
				$post = self::table($post, ++$recurse_level);
			}
		}
		
		return $post;
	}

	/* Condenses text into a shorter string */
	public static function snippet($text, $snippet_length = 80) {
		if($text === '') {
			return '~';
		}
		
		/* Remove quotes and citations */
		$text = preg_replace('/(@|>)(.*)/m', ' ~ ', $text);
		/* Merge tildes from the above into one */
		$text = preg_replace('/ ~ [\s~]+/', ' ~ ', $text);
		/* Remove mark-up syntax */
		$text = preg_replace(self::$markup, '$1', $text);
		/* Strip line-breaks */
		$text = str_replace( array("\r", "\n"), ' ', $text );
		$text = htmlspecialchars($text);
		
		if(ctype_digit($_SESSION['settings']['snippet_length'])) {
			$snippet_length = $_SESSION['settings']['snippet_length'];
		}
		
		if(strlen($text) > $snippet_length) {
			$text = substr($text, 0, $snippet_length) . '&hellip;';
		}
		
		return $text;
	}
}
?>