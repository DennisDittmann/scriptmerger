<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) Stefan Galinski <stefan@sgalinski.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * This class contains the parsing and replacing functionality for javascript files
 */
class ScriptmergerJavascript extends ScriptmergerBase {
	/**
	 * holds the javascript code
	 *
	 * Structure:
	 * - $file
	 *   |-content => string
	 *   |-basename => string (base name of $file without file prefix)
	 *   |-minify-ignore => bool
	 *   |-merge-ignore => bool
	 *
	 * @var array
	 */
	protected $javascript = array();

	/**
	 * Controller for the processing of the javascript files.
	 *
	 * @return void
	 */
	public function process() {
		// fetch all javascript content
		$this->getFiles();

		// minify, compress and merging
		foreach ($this->javascript as $section => $javascriptBySection) {
			$mergedContent = '';
			$firstFreeIndex = -1;
			foreach ($javascriptBySection as $index => $javascriptProperties) {
				$newFile = '';

				// file should be minified
				if ($this->configuration['javascript.']['minify.']['enable'] === '1' &&
					!$javascriptProperties['minify-ignore']
				) {
					$newFile = $this->minifyFile($javascriptProperties);
				}

				// file should be merged
				if ($this->configuration['javascript.']['merge.']['enable'] === '1' &&
					!$javascriptProperties['merge-ignore']
				) {
					if ($firstFreeIndex < 0) {
						$firstFreeIndex = $index;
					}

					// add content
					$mergedContent .= $javascriptProperties['content'] . LF;

					// remove file from array
					unset($this->javascript[$section][$index]);

					// we doesn't need to compress or add a new file to the array,
					// because the last one will finally not be needed anymore
					continue;
				}

				// file should be compressed instead?
				if ($this->configuration['javascript.']['compress.']['enable'] === '1' &&
					function_exists('gzcompress') && !$javascriptProperties['compress-ignore']
				) {
					$newFile = $this->compressFile($javascriptProperties);
				}

				// minification or compression was used
				if ($newFile !== '') {
					$this->javascript[$section][$index]['file'] = $newFile;
					$this->javascript[$section][$index]['content'] =
						$javascriptProperties['content'];
					$this->javascript[$section][$index]['basename'] =
						$javascriptProperties['basename'];
				}
			}

			// save merged content inside a new file
			if ($this->configuration['javascript.']['merge.']['enable'] === '1' && $mergedContent !== '') {
				// create property array
				$properties = array(
					'content' => $mergedContent,
					'basename' => $section . '-' . md5($mergedContent) . '.merged'
				);

				// write merged file in any case
				$newFile = $this->tempDirectories['merged'] . $properties['basename'] . '.js';
				if (!file_exists($newFile)) {
					$this->writeFile($newFile, $properties['content']);
				}

				// file should be compressed
				if ($this->configuration['javascript.']['compress.']['enable'] === '1' &&
					function_exists('gzcompress')
				) {
					$newFile = $this->compressFile($properties);
				}

				// add new entry
				$this->javascript[$section][$firstFreeIndex]['file'] = $newFile;
				$this->javascript[$section][$firstFreeIndex]['content'] =
					$properties['content'];
				$this->javascript[$section][$firstFreeIndex]['basename'] =
					$properties['basename'];
			}
		}

		// write javascript content back to the document
		$this->writeToDocument();
	}

	/**
	 * This method parses the output content and saves any found javascript files or inline code
	 * into the "javascript" class property. The output content is cleaned up of the found results.
	 *
	 * @return array js files
	 */
	protected function getFiles() {
		// init
		$javascriptTags = array(
			'head' => array(),
			'body' => array()
		);

		// create search pattern
		$searchScriptsPattern = '/' .
			'<script' . // This expression includes any script nodes.
			'(?=.+?(?:src="(.*?)"|>))' . // It fetches the src attribute.
			'[^>]*?>' . // Finally we finish the parsing of the opening tag
			'.*?<\/script>\s*' . // until the closing tag.
			'/is';

		// filter pattern for the inDoc scripts (fetches the content)
		$filterInDocumentPattern = '/' .
			'<script.*?>' . // The expression removes the opening script tag
			'(?:.*?\/\*<!\[CDATA\[\*\/)?' . // and the optionally prefixed CDATA string.
			'(?:.*?<!--)?' . // senseless <!-- construct
			'\s*(.*?)' . // We save the pure js content,
			'(?:\s*\/\/\s*-->)?' . // senseless <!-- construct
			'(?:\s*\/\*\]\]>\*\/)?' . // remove the possible closing CDATA string
			'\s*<\/script>' . // and closing script tag
			'/is';

		// fetch the head content
		$head = array();
		$pattern = '/<head>.+?<\/head>/is';
		preg_match($pattern, $GLOBALS['TSFE']->content, $head);
		$head = $oldHead = $head[0];

		// parse all available js code inside script tags
		preg_match_all($searchScriptsPattern, $head, $javascriptTags['head']);

		// remove any js code inside the output content
		if (count($javascriptTags['head'][0])) {
			$head = preg_replace($searchScriptsPattern, '', $head, count($javascriptTags['head'][0]));
			$GLOBALS['TSFE']->content = str_replace($oldHead, $head, $GLOBALS['TSFE']->content);
		}

		// fetch the body content
		if ($this->configuration['javascript.']['parseBody'] === '1') {
			$body = array();
			$pattern = '/<body.*>.+?<\/body>/is';
			preg_match($pattern, $GLOBALS['TSFE']->content, $body);
			$body = $oldBody = $body[0];

			// parse all available js code inside script tags
			preg_match_all($searchScriptsPattern, $body, $javascriptTags['body']);

			// replace any js code inside the output content with markers of the form ###43### at the original
			// places to write them back later if required
			$amountOfScriptTags = count($javascriptTags['body'][0]);
			if ($amountOfScriptTags) {
				$function = create_function('', 'static $i = 0; return \'###MERGER\' . $i++ . \'MERGER###\';');
				$body = preg_replace_callback($searchScriptsPattern, $function, $body, $amountOfScriptTags);
				$GLOBALS['TSFE']->content = str_replace($oldBody, $body, $GLOBALS['TSFE']->content);
			}
		}

		// parse matches
		foreach ($javascriptTags as $section => $results) {
			$amountOfResults = count($results[0]);
			for ($i = 0; $i < $amountOfResults; ++$i) {
				// get source attribute
				$source = trim($results[1][$i]);
				$isSourceFromMainAttribute = FALSE;
				if ($source !== '') {
					preg_match('/^<script([^>]*)>/', trim($results[0][$i]), $scriptAttribute);
					$isSourceFromMainAttribute = (strpos($scriptAttribute[1], $source) !== FALSE);
				}

				// add basic entry
				$this->javascript[$section][$i]['minify-ignore'] = FALSE;
				$this->javascript[$section][$i]['compress-ignore'] = FALSE;
				$this->javascript[$section][$i]['merge-ignore'] = FALSE;
				$this->javascript[$section][$i]['file'] = $source;
				$this->javascript[$section][$i]['content'] = '';
				$this->javascript[$section][$i]['basename'] = '';
				$this->javascript[$section][$i]['addInDocument'] = FALSE;

				if ($isSourceFromMainAttribute) {
					// try to fetch the content of the css file
					$file = $source;
					if ($GLOBALS['TSFE']->absRefPrefix !== '' && strpos($file, $GLOBALS['TSFE']->absRefPrefix) === 0) {
						$file = substr($file, strlen($GLOBALS['TSFE']->absRefPrefix) - 1);
					}
					$file = PATH_site . $file;

					if (file_exists($file)) {
						$content = file_get_contents($file);
					} else {
						$content = $this->getExternalFile($source, TRUE);
					}

					// ignore this file if the content could not be fetched
					if (trim($content) === '') {
						$this->javascript[$section][$i]['minify-ignore'] = TRUE;
						$this->javascript[$section][$i]['compress-ignore'] = TRUE;
						$this->javascript[$section][$i]['merge-ignore'] = TRUE;
						continue;
					}

					// check if the file should be ignored for some processes
					if ($this->configuration['javascript.']['minify.']['ignore'] !== '' &&
						preg_match($this->configuration['javascript.']['minify.']['ignore'], $source)
					) {
						$this->javascript[$section][$i]['minify-ignore'] = TRUE;
					}

					if ($this->configuration['javascript.']['compress.']['ignore'] !== '' &&
						preg_match($this->configuration['javascript.']['compress.']['ignore'], $source)
					) {
						$this->javascript[$section][$i]['compress-ignore'] = TRUE;
					}

					if ($this->configuration['javascript.']['merge.']['ignore'] !== '' &&
						preg_match($this->configuration['javascript.']['merge.']['ignore'], $source)
					) {
						$this->javascript[$section][$i]['merge-ignore'] = TRUE;
					}

					// set the javascript file with it's content
					$this->javascript[$section][$i]['file'] = $source;
					$this->javascript[$section][$i]['content'] = $content;

					// get base name for later usage
					// base name without file prefix and prefixed hash of the content
					$filename = basename($source);
					$hash = md5($content);
					$this->javascript[$section][$i]['basename'] =
						substr($filename, 0, strrpos($filename, '.')) . '-' . $hash;

				} else {
					// styles which are added inside the document must be parsed again
					// to fetch the pure js code
					$javascriptContent = array();
					preg_match_all($filterInDocumentPattern, $results[0][$i], $javascriptContent);

					// we doesn't need to continue if it was an empty style tag
					if ($javascriptContent[1][0] === '') {
						unset($this->javascript[$section][$i]);
						continue;
					}

					// save the content into a temporary file
					$hash = md5($javascriptContent[1][0]);
					$source = $this->tempDirectories['temp'] . 'inDocument-' . $hash;

					if (!file_exists($source . '.js')) {
						$this->writeFile($source . '.js', $javascriptContent[1][0]);
					}

					// try to resolve any @import occurrences
					$this->javascript[$section][$i]['file'] = $source . '.js';
					$this->javascript[$section][$i]['content'] = $javascriptContent[1][0];
					$this->javascript[$section][$i]['basename'] = basename($source);

					// inDocument styles of the body shouldn't be removed from their position
					if ($this->configuration['javascript.']['doNotRemoveInDocInBody'] === '1' && $section === 'body') {
						$this->javascript[$section][$i]['minify-ignore'] = FALSE;
						$this->javascript[$section][$i]['compress-ignore'] = TRUE;
						$this->javascript[$section][$i]['merge-ignore'] = TRUE;
						$this->javascript[$section][$i]['addInDocument'] = TRUE;
					}
				}
			}
		}
	}

	/**
	 * This method minifies a javascript file. It's based upon the JSMin+ class
	 * of the project minify. Alternatively the old JSMin class can be used, but it's
	 * definitely not the preferred solution!
	 *
	 * @param array $properties properties of an entry (copy-by-reference is used!)
	 * @return string new filename
	 */
	protected function minifyFile(&$properties) {
		// stop further processing if the file already exists
		$newFile = $this->tempDirectories['minified'] . $properties['basename'] . '.min.js';
		if (file_exists($newFile)) {
			$properties['basename'] .= '.min';
			$properties['content'] = file_get_contents($newFile);
			return $newFile;
		}

		// check for conditional compilation code to fix an issue with jsmin+
		$hasConditionalCompilation = FALSE;
		if ($this->configuration['javascript.']['minify.']['useJSMinPlus'] === '1') {
			$hasConditionalCompilation = preg_match('/\/\*@cc_on/is', $properties['content']);
		}

		// minify content (the ending semicolon must be added to prevent minimisation bugs)
		$hasErrors = FALSE;
		$minifiedContent = '';
		try {
			if (!$hasConditionalCompilation && $this->configuration['javascript.']['minify.']['useJShrink'] === '1') {
				if (!class_exists('JShrink\Minifier', FALSE)) {
					require_once(t3lib_extMgm::extPath('scriptmerger') . 'Resources/JShrink/Minifier.php');
				}

				$minifiedContent = JShrink\Minifier::minify($properties['content']);
			} elseif (!$hasConditionalCompilation && $this->configuration['javascript.']['minify.']['useJSMinPlus'] === '1') {
				if (!class_exists('JSMinPlus', FALSE)) {
					require_once(t3lib_extMgm::extPath('scriptmerger') . 'Resources/jsminplus.php');
				}

				$minifiedContent = JSMinPlus::minify($properties['content']);

			} else {
				if (!class_exists('JSMin', FALSE)) {
					require_once(t3lib_extMgm::extPath('scriptmerger') . 'Resources/jsmin.php');
				}

				/** @noinspection PhpUndefinedClassInspection */
				$minifiedContent = JSMin::minify($properties['content']);
			}
		} catch (Exception $exception) {
			$hasErrors = TRUE;
		}

		// check if the minified content has more than two characters or more than 50 lines and no errors occurred
		if (!$hasErrors && (strlen($minifiedContent) > 2 || count(explode(LF, $minifiedContent)) > 50)) {
			$properties['content'] = $minifiedContent . ';';
		} else {
			$message = 'This javascript file could not be minified: "' . $properties['file'] . '"! ' .
				'You should exclude it from the minification process!';
			t3lib_div::sysLog($message, 'scriptmerger', t3lib_div::SYSLOG_SEVERITY_ERROR);
		}

		$this->writeFile($newFile, $properties['content']);
		$properties['basename'] .= '.min';

		return $newFile;
	}

	/**
	 * This method compresses a javascript file.
	 *
	 * @param array $properties properties of an entry (copy-by-reference is used!)
	 * @return string new filename
	 */
	protected function compressFile(&$properties) {
		$newFile = $this->tempDirectories['compressed'] . $properties['basename'] . '.gz.js';
		if (file_exists($newFile)) {
			return $newFile;
		}

		$this->writeFile($newFile, gzencode($properties['content'], 5));

		return $newFile;
	}

	/**
	 * This method writes the javascript back to the document.
	 *
	 * @return void
	 */
	protected function writeToDocument() {
		// write all files back to the document
		foreach ($this->javascript as $section => $javascriptBySection) {
			ksort($javascriptBySection);
			if (!is_array($javascriptBySection)) {
				continue;
			}

			// prepare pattern
			$addToBody = ($section === 'body' || $this->configuration['javascript.']['addBeforeBody'] === '1');
			if ($addToBody) {
				$pattern = '/<\/body>/i';
			} else {
				$pattern = '/<(?:\/base|base|meta name="generator"|link|\/title).*?>/is';
				$javascriptBySection = array_reverse($javascriptBySection);
			}

			foreach ($javascriptBySection as $index => $javascriptProperties) {
				$file = $javascriptProperties['file'];

				// normal file or http link?
				if (file_exists($file)) {
					$file = $GLOBALS['TSFE']->absRefPrefix .
						(PATH_site === '/' ? $file : str_replace(PATH_site, '', $file));
				}

				// build javascript script link or add the content directly into the document
				if ($javascriptProperties['addInDocument'] ||
					$this->configuration['javascript.']['addContentInDocument'] === '1'
				) {
					$content = "\t" .
						'<script type="text/javascript">' . LF .
						"\t" . '/* <![CDATA[ */' . LF .
						"\t" . $javascriptProperties['content'] . LF .
						"\t" . '/* ]]> */' . LF .
						"\t" . '</script>' . LF;
				} elseif ($this->configuration['javascript.']['deferLoading'] === '1') {
					$content = '
						<script type="text/javascript" defer="defer">
							function downloadJSAtOnload() {
								var element = document.createElement("script");
								element.src = "' . $file . '";
								document.body.appendChild(element);
							}

							if (window.addEventListener) {
								window.addEventListener("load", downloadJSAtOnload, false);
							} else if (window.attachEvent) {
								window.attachEvent("onload", downloadJSAtOnload);
							} else {
								window.onload = downloadJSAtOnload;
							}
					</script>';
				} else {
					$content = "\t" .
						'<script type="text/javascript" src="' . $file . '"></script>' . LF;
				}

				// add body scripts back to their original place if they were ignored
				if ($section === 'body' && $javascriptProperties['merge-ignore']) {
					$GLOBALS['TSFE']->content = str_replace(
						'###MERGER' . $index . 'MERGER###',
						$content,
						$GLOBALS['TSFE']->content
					);
					continue;
				}

				// add content right after the opening head tag or inside the body
				$replacement = ($addToBody ? $content . '\0' : '\0' . $content);
				$GLOBALS['TSFE']->content = preg_replace($pattern, $replacement, $GLOBALS['TSFE']->content, 1);
			}
		}

		// remove all empty body markers
		if ($this->configuration['javascript.']['parseBody'] === '1') {
			$pattern = '/###MERGER[0-9]*?MERGER###/is';
			$GLOBALS['TSFE']->content = preg_replace($pattern, '', $GLOBALS['TSFE']->content);
		}
	}
}

?>