<?php

namespace WordPress_org\Main_2022\ExportToPatterns\Parsers;

class HTMLParser implements BlockParser {
	use GetSetAttribute;

	public $tags = [];
	public $attributes = [];
	public $min_string_length = 0;

	public function __construct( $tags = array(), $attributes = array(), $min_string_length = 0 ) {
		$this->tags              = (array) $tags;
		$this->attributes        = (array) $attributes;
		$this->min_string_length = (int) $min_string_length;
	}

	public function to_strings( array $block ) : array {
		$strings = $this->get_attribute( 'placeholder', $block );

		foreach ( $this->tags as $tag ) {
			$tag = $this->escape_tag( $tag, '#' );

			if ( preg_match_all( "#<{$tag}[^>]*>\s*(?P<string>.+?)\s*</{$tag}>#is", $block['innerHTML'], $matches ) ) {
				$strings = array_merge( $strings, $matches['string'] );
			}
		}

		foreach ( $this->attributes as $attr ) {
			$attr = $this->escape_attr( $attr, '#' );
			$found_strings = [];

			if (
				str_contains( $block['innerHTML'], "='" ) &&
				preg_match_all( "#{$attr}='(?P<string>[^']+?)'#is", $block['innerHTML'], $matches )
			) {
				$found_strings = $matches['string'];
			}

			if (
				str_contains( $block['innerHTML'], '="' ) &&
				preg_match_all( "#{$attr}=\"(?P<string>[^\"]+?)\"#is", $block['innerHTML'], $matches )
			) {
				$found_strings = $matches['string'];
			}

			// Don't translate certain types of links, they correspond to other points on the page.
			if ( 'href' === $attr ) {
				$found_strings = array_filter(
					$found_strings,
					function( $value ) {
						// Anchors.
						if ( str_starts_with( $value, '#' ) ) {
							return false;
						}

						// root relative URLs, which are not protocol-relative.
						if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
							return false;
						}

						// relative to current page urls.
						if (
							! str_starts_with( $value, 'https://' ) &&
							! str_starts_with( $value, 'http://' ) &&
							! str_starts_with( $value, '//' )
						) {
							return false;
						}

						return true;
					}
				);
			}

			$strings = array_merge( $strings, $found_strings );
		}

		if ( $this->min_string_length ) {
			$strings = array_filter(
				$strings,
				function( $string ) {
					if ( function_exists( 'mb_strlen' ) ) {
						return mb_strlen( $string ) >= $this->min_string_length;
					}

					return strlen( $string ) >= $this->min_string_length;
				}
			);
		}

		return $strings;
	}

	// todo: this needs a fix to properly rebuild innerContent - see ParagraphParserTest
	public function replace_strings( array $block, array $replacements ) : array {
		$this->set_attribute( 'placeholder', $block, $replacements );

		$html = $block['innerHTML'];
		$content = $block['innerContent'];

		foreach ( $this->to_strings( $block ) as $original ) {
			if ( empty( $original ) || ! isset( $replacements[ $original ] ) ) {
				continue;
			}

			// TODO: Potentially this should be more specific for tags/attribute replacements as needed.
			$regex = '#([>"\'])\s*' . preg_quote( $original, '#' ) . '\s*([\'"<])#s';
			$html  = preg_replace( $regex, '$1' . addcslashes( $replacements[ $original ], '\\$' ) . '$2', $html );

			foreach ( $content as $i => $chunk ) {
				if ( ! empty( $chunk ) ) {
					$content[ $i ] = preg_replace( $regex, '$1' . addcslashes( $replacements[ $original ], '\\$' ) . '$2', $chunk );
				}
			}
		}

		$block['innerHTML']    = $html;
		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * Escape a tag/attribute to use in a regex.
	 */
	protected function escape_tag( $string, $delim ) {
		return $this->escape( $string, $delim );
	}
	protected function escape_attr( $string, $delim ) {
		return $this->escape( $string, $delim );
	}
	protected function escape( $string, $delim ) {
		return preg_quote( $string, $delim );
	}
}

class HTMLRegexParser extends HTMLParser {
	/**
	 * Maybe escape a string for a regex match, unless it looks like regex (ie. /..../) then use as-is.
	 */
	protected function escape_tag( $string, $delim ) {
		if ( str_starts_with( $string, '/' ) && str_ends_with( $string, '/' ) ) {
			return trim( $string, '/' );
		}

		return parent::escape_tag( $string, $delim );
	}
}
