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

// admin/themes/default/template/photos_add_direct.tpl
$lang['Album %s now contains %d photos'] = 'Album %s enthält jetzt %d Fotos';
$lang['%d photos updated'] = '%d Fotos aktualisiert';

// admin/themes/default/template/batch_manager_global.tpl and include/batch_manager_filter.inc.tpl
$lang['Batch Manager Filter'] = 'Filter der Stapelverarbeitung';
$lang['No filter, add one'] = 'Kein Filter, fügen Sie einen hinzu';

// admin/themes/default/template/tags.tpl
$lang['Rename Tag'] = 'Schlagwort umbenennen';

// plugins/typetags
$lang['Remove color'] = 'Farbe entfernen';
$lang['Add tag'] = 'Schlagwort hinzufügen';
$lang['Remove tag'] = 'Schlagwort entfernen';
$lang['Couleur'] = 'Farbe';
