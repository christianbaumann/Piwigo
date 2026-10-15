<?php
/*
 * Pure functions: Titel, Autor, Info, an album's name and its description are
 * plain text (decision 0073). No core dependency, so the unit suite loads this
 * file on its own.
 */

/**
 * The text as HTML that shows it verbatim, markup and quotes included.
 *
 * @param string|null $text
 * @return string|null null stays null, so core's emptiness checks still hold
 */
function galleryrules_plain_text($text)
{
  if ($text === null)
  {
    return null;
  }
  return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Plain text that keeps its lines: escaped first, so the line breaks are the
 * only markup.
 *
 * @param string|null $text
 * @return string|null
 */
function galleryrules_plain_text_lines($text)
{
  if ($text === null)
  {
    return null;
  }
  return nl2br(galleryrules_plain_text($text));
}
