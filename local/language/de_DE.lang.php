<?php
// Fork-local German strings that Piwigo core and the Colored Tags plugin leave
// untranslated. Loaded by include/common.inc.php:239 through
// load_language('lang', PWG_LOCAL_DIR, array('local'=>true)), which resolves to
// this flat file, and merged over core after common.lang and admin.lang - so
// these win. A plugin's own load_language() runs later and would overwrite a key
// it also defines; none of the keys below is defined in
// plugins/typetags/language/de_DE/plugin.lang.php.
//
// The %s and %d placeholders are fed through sprintf. Their number and order
// must survive any edit.

// admin/themes/default/template/cat_modify.tpl
$lang['Album updated'] = 'Album gespeichert';
$lang['An error has occured while saving album settings'] = 'Beim Speichern der Albumeinstellungen ist ein Fehler aufgetreten';
$lang['No photos in the current album, no thumbnail available'] = 'Keine Fotos in diesem Album, kein Vorschaubild verfügbar';

// admin/themes/default/template/albums.tpl
$lang['Rename album'] = 'Album umbenennen';

// admin/themes/default/template/cat_modify.tpl - the album info box after a save
$lang['Just now'] = 'Gerade eben';

// admin/themes/default/template/photos_add_direct.tpl
$lang['Album %s now contains %d photos'] = 'Album %s enthält jetzt %d Fotos';
$lang['%d photos updated'] = '%d Fotos aktualisiert';

// admin/picture_modify.php - the "added on" line of the photo properties screen
$lang['Posted the %s'] = 'Eingestellt am %s';

// admin/themes/default/template/batch_manager_global.tpl and include/batch_manager_filter.inc.tpl
$lang['Batch Manager Filter'] = 'Filter der Stapelverarbeitung';
$lang['No filter, add one'] = 'Kein Filter, fügen Sie einen hinzu';

// admin/themes/default/template/tags.tpl
$lang['Rename Tag'] = 'Schlagwort umbenennen';

// plugins/typetags
$lang['Remove color'] = 'Farbe entfernen';
$lang['Add tag'] = 'Schlagwort hinzufügen';
$lang['Remove tag'] = 'Schlagwort entfernen';
$lang['Type a new tag'] = 'Neues Schlagwort eingeben';
$lang['Add the tag'] = 'Hinzufügen';
$lang['The tag was not saved'] = 'Das Schlagwort wurde nicht gespeichert';
$lang['Session expired - please sign in again'] = 'Sitzung abgelaufen – bitte neu anmelden';
$lang['Invalid tag name'] = 'Ungültiger Schlagwortname';
$lang['You have typed in too many new tags today'] = 'Heute wurden schon zu viele neue Schlagworte eingegeben';
$lang['Photo is in no visible album'] = 'Foto ist in keinem sichtbaren Album';
$lang['Couleur'] = 'Farbe';

// plugins/persons/include/functions.inc.php, PERSONS_NAME_REQUIRED_MESSAGE
$lang['A person needs a name'] = 'Eine Person braucht einen Namen';

// Core German strings replaced on purpose (docs/agents/decisions/0052): core's own
// wording is a du-imperative, English, or a verb where a noun belongs.
// admin/themes/default/template/tags.tpl - the question about tags no photo uses, and duplicating
$lang['Delete them'] = 'Löschen';
$lang['Keep them'] = 'Behalten';
$lang[' (copy)'] = ' (Kopie)';
$lang['Tag name'] = 'Name des Schlagworts';
// admin/themes/default/template/include/batch_manager_filter.inc.tpl
$lang['include child albums'] = 'Unteralben einbeziehen';
// admin/themes/default/template/tags.tpl and group_list.tpl - the merge modal; core's
// translation keeps the key's braces around the merged names
$lang['Tag(s) \{%s1\} succesfully merged into "%s2"'] = 'Schlagwort(e) %s1 wurde(n) erfolgreich in »%s2« zusammengeführt';
$lang['Group(s) \{%s1\} succesfully merged into "%s2"'] = 'Gruppe(n) %s1 erfolgreich in "%s2" zusammengeführt';
// admin/site_update.php - PWG-UPDATE-1; core's translation leaves out the digits
$lang['The name of directories and files must be composed of letters, numbers, "-", "_" or "."'] = 'Verzeichnis- und Dateinamen müssen aus Buchstaben, Ziffern oder den Zeichen "-", "_" oder "." bestehen.';
