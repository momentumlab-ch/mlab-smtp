<?php
/**
 * Werkzeug für die Übersetzungen (ohne gettext-Programme): sync und check.
 *
 *   php bin/i18n.php sync   übernimmt alle Texte der POT in en_US, fr_FR und it_IT (vorhandene Übersetzungen bleiben)
 *   php bin/i18n.php check  prüft die .po-Dateien: vollständig, keine Fuzzy-Einträge, Platzhalter, HTML, Plural, .mo
 *
 * Quelltexte sind Deutsch. Exit 1 bei Fehlern.
 */
require dirname( __DIR__ ) . '/tests/env/vendor/autoload.php';

use Gettext\Translations;

const LOCALES = array(
	'en_US' => 'nplurals=2; plural=(n != 1);',
	'fr_FR' => 'nplurals=2; plural=(n > 1);',
	'it_IT' => 'nplurals=2; plural=(n != 1);',
);
$root = dirname( __DIR__ ) . '/languages';
$cmd  = $argv[1] ?? 'check';

/** Platzhalter eines Textes als sortierte Liste. */
function placeholders( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[-+0 #]*\d*(?:\.\d+)?[sdfuxXoeEgGcb]/', str_replace( '%%', '', $text ), $m );
	$list = $m[0];
	sort( $list );
	return $list;
}

/** HTML-Tags eines Textes als sortierte Liste. */
function tags( string $text ): array {
	preg_match_all( '/<\/?[a-zA-Z][^>]*>/', $text, $m );
	$list = array_map( static fn( $t ) => strtolower( preg_replace( '/\s+/', ' ', $t ) ), $m[0] );
	sort( $list );
	return $list;
}

if ( 'sync' === $cmd ) {
	$pot = Translations::fromPoFile( "$root/mlab-smtp.pot" );
	foreach ( LOCALES as $locale => $plural ) {
		$file = "$root/mlab-smtp-$locale.po";
		$old  = is_file( $file ) ? Translations::fromPoFile( $file ) : new Translations();
		$new  = new Translations();
		$new->setLanguage( $locale );
		$new->setHeader( 'Project-Id-Version', 'MomentumLab SMTP' );
		$new->setHeader( 'Report-Msgid-Bugs-To', 'https://github.com/momentumlab-ch/mlab-smtp/issues' );
		$new->setHeader( 'Language-Team', 'MomentumLab' );
		$new->setHeader( 'Plural-Forms', $plural );
		$new->setHeader( 'X-Domain', 'mlab-smtp' );
		$new->setHeader( 'Content-Type', 'text/plain; charset=UTF-8' );
		$kept = 0;
		foreach ( $pot as $entry ) {
			$copy = clone $entry;
			$prev = $old->find( $entry->getContext(), $entry->getOriginal() );
			if ( $prev && '' !== $prev->getTranslation() ) {
				$copy->setTranslation( $prev->getTranslation() );
				$copy->setPluralTranslations( $prev->getPluralTranslations() );
				++$kept;
			}
			$new[] = $copy;
		}
		$new->toPoFile( $file );
		printf( "%s: %d Texte, %d übernommen, %d offen\n", basename( $file ), count( $new ), $kept, count( $new ) - $kept );
	}
	exit( 0 );
}

// ---- check
$errors = array();
$pot    = Translations::fromPoFile( "$root/mlab-smtp.pot" );
foreach ( LOCALES as $locale => $plural ) {
	$file = "$root/mlab-smtp-$locale.po";
	if ( ! is_file( $file ) ) {
		$errors[] = "$locale: .po fehlt";
		continue;
	}
	$po = Translations::fromPoFile( $file );
	if ( $po->getHeader( 'Plural-Forms' ) !== $plural ) {
		$errors[] = "$locale: Plural-Forms ist '" . $po->getHeader( 'Plural-Forms' ) . "', erwartet '$plural'";
	}
	if ( $po->getLanguage() !== $locale ) {
		$errors[] = "$locale: Language-Header ist '" . $po->getLanguage() . "'";
	}
	$open = 0;
	foreach ( $pot as $entry ) {
		$o   = $entry->getOriginal();
		$tr  = $po->find( $entry->getContext(), $o );
		$id  = $locale . ': «' . mb_substr( $o, 0, 50 ) . '»';
		if ( ! $tr || '' === $tr->getTranslation() ) {
			++$open;
			continue;
		}
		if ( $tr->hasFlag( 'fuzzy' ) ) {
			$errors[] = "$id ist Fuzzy";
		}
		$forms = array( $tr->getTranslation() );
		if ( $entry->hasPlural() ) {
			$extra = $tr->getPluralTranslations();
			if ( count( $extra ) + 1 !== 2 ) {
				$errors[] = "$id: Plural braucht 2 Formen, hat " . ( count( $extra ) + 1 );
			}
			$forms = array_merge( $forms, $extra );
		}
		foreach ( $forms as $i => $form ) {
			if ( '' === trim( $form ) ) {
				$errors[] = "$id: Form $i leer";
				continue;
			}
			$source = ( $entry->hasPlural() && $i > 0 ) ? $entry->getPlural() : $o;
			if ( placeholders( $form ) !== placeholders( $source ) && ( 0 === $i || placeholders( $form ) !== placeholders( $o ) ) ) {
				$errors[] = "$id: Platzhalter ungleich (Form $i): " . implode( ' ', placeholders( $source ) ) . ' | ' . implode( ' ', placeholders( $form ) );
			}
			if ( tags( $form ) !== tags( $source ) ) {
				$errors[] = "$id: HTML-Tags ungleich (Form $i)";
			}
			if ( rtrim( $form ) !== $form && rtrim( $source ) === $source ) {
				$errors[] = "$id: Leerraum am Ende (Form $i)";
			}
		}
	}
	if ( $open > 0 ) {
		$errors[] = "$locale: $open Texte ohne Übersetzung";
	}
	$mo = "$root/mlab-smtp-$locale.mo";
	if ( ! is_file( $mo ) ) {
		$errors[] = "$locale: .mo fehlt";
	} elseif ( filemtime( $mo ) + 2 < filemtime( $file ) ) {
		$errors[] = "$locale: .mo ist älter als die .po (bitte bin/i18n-sync.sh)";
	} else {
		$count = count( Translations::fromMoFile( $mo ) );
		if ( $count < count( $pot ) ) {
			$errors[] = "$locale: .mo enthält $count Texte, POT hat " . count( $pot );
		}
	}
	printf( "%s: %d Texte geprüft\n", $locale, count( $pot ) );
}
foreach ( array_slice( $errors, 0, 60 ) as $e ) {
	echo "FAIL: $e\n";
}
if ( count( $errors ) > 60 ) {
	echo 'FAIL: … und ' . ( count( $errors ) - 60 ) . " weitere\n";
}
echo $errors ? "\n" . count( $errors ) . " Fehler\n" : "\nAlle Übersetzungen vollständig und konsistent\n";
exit( $errors ? 1 : 0 );
