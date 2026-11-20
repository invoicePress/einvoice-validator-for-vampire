=== eInvoice Validator for VAMPIRE PHP ===
Contributors: naranili
Tags: e-rechnung, xrechnung, zugferd, peppol, validator
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prüft E-Rechnungen auf einer Seite Ihrer eigenen Website, in reinem PHP - ohne Java, ohne Hintergrunddienst, ohne fremden Server.

== Description ==

eInvoice Validator for VAMPIRE PHP prüft elektronische Rechnungen nach EN 16931 und ihren nationalen und branchenbezogenen Regeln. Besucher ziehen eine Rechnung auf eine Seite, das Plugin prüft sie auf Ihrem Server und zeigt den Bericht auf der Seite, mit einem Download des Berichts als XML.

Die Prüfmaschine **VAMPIRE PHP** ist enthalten. Sie läuft im selben PHP-Prozess wie WordPress, deshalb arbeitet das Plugin auch auf gewöhnlichem Webspace: Es braucht keine Java-Laufzeit, keinen Dienst und kein weiteres Plugin.

= Was es prüft =

* XRechnung 3.0.2 ( UBL Invoice, UBL CreditNote und CII, auch Extension und CVD )
* Die Kernregeln der EN 16931 für UBL und CII
* Peppol BIS Billing 3.0.21, Invoice und CreditNote
* Factur-X / ZUGFeRD 2.5.2 in den Profilen EN16931 und EXTENDED

Eine Rechnung in einem Format, dessen Regelwerk nicht enthalten ist, bekommt das Ergebnis „Kein Ergebnis“ mit dem fehlenden Regelwerk als Grund, nie ein falsches „Abgelehnt“.

= Was es kann =

* **Hochladen per Ziehen und Ablegen** von XML-Rechnungen und von ZUGFeRD- und Factur-X-PDFs. Die Rechnungs-XML wird **im Browser des Besuchers** aus dem PDF geholt; nur die XML erreicht den Server.
* **Ein lesbarer Bericht**: Ergebnis, Prüfschritte, jeder Befund in Klartext mit dem betroffenen Feld, und eine Ansicht der Rechnung Feld für Feld.
* **Download des Berichts** im Berichtsformat des KoSIT-Validators, und eine REST-Route `POST /wp-json/einvoice/v1/validate-php`.
* **Positiver Prüfbericht** auf Wunsch: Der Bericht führt jede geprüfte Regel mit ihrem Ergebnis auf, auch die erfüllten.
* **Gegen jede Fassung prüfen** auf Wunsch: ZUGFeRD- und Factur-X-Rechnungen werden gegen jede Fassung ihres Formats geprüft, und eine Tabelle zeigt das Ergebnis je Fassung.
* **Regelpakete und Regelprofile**: Neue Regelwerke lassen sich als ZIP-Datei einspielen, ohne das Plugin zu ersetzen, und eigene Regelprofile aus dem Rule Studio ergänzen die offiziellen Regeln.
* **Schutz beim Hochladen**: Grössengrenze, eine tägliche Grenze je Rolle mit eigener Zeile für Gäste, Schutz mit einem frisch abgerufenen Nonce auch auf zwischengespeicherten Seiten, und keine bleibende Kopie der Rechnung.

= Zusammen mit den anderen Werkzeugen =

Ist **eInvoice Viewer for PEPPOL & EN16931** aktiv, bekommt eine im Viewer geöffnete Rechnung den Knopf „Mit VAMPIRE prüfen“. Das ist freiwillig; einzustellen ist nichts.

= Die Regelpakete =

Die Regelpakete sind kein eigenes Werk dieses Plugins. Jedes ist maschinell aus einem veröffentlichten Schematron-Regelwerk von CEN, KoSIT, OpenPeppol oder FeRD abgeleitet und trägt die Lizenz seiner Quelle. Die Datei `vendor/invoicepress/vampire-php/NOTICE` nennt Herkunft, Rechteinhaber und Lizenz jedes Regelpakets, und die Lizenztexte liegen daneben.

= Quellcode und Bau =

Die Skripte in `assets/js/`, deren Name auf `.bundle.js` endet, baut esbuild aus den ES-Modulen in `assets/js/source/`, die mit dem Plugin ausgeliefert werden. `assets/js/source/BUILD.txt` beschreibt, wie man sie neu baut, und `assets/js/source/build.mjs` tut es mit einem Befehl; mit der dort genannten Fassung von esbuild ist das Ergebnis byte-gleich mit den ausgelieferten Dateien. Die Datentabelle `assets/js/source/src/model/en16931-versions.json` schreibt der Generator `assets/js/source/tools/build-en16931-structure.mjs`, der ebenfalls beiliegt.

Die Übersetzungstabellen `i18n/de.json.js` und `i18n/fr.json.js` entstehen aus `languages/einvoice-validator-for-vampire-de_DE.po` und `languages/einvoice-validator-for-vampire-fr_FR.po`. Jede Tabelle enthält die Paare aus msgid und msgstr, die die Skripte verwenden.

Der Ordner `assets/js/pdfjs/` enthält Mozilla pdf.js 6.3.289 unverändert, unter der Apache License 2.0, deren Text neben den Dateien liegt. Der Quellcode steht unter https://github.com/mozilla/pdf.js.

== External services ==

**Dieses Plugin ruft keinen fremden Dienst auf.** Es stellt keine Anfrage nach aussen - nicht beim Laden einer Seite, nicht beim Hochladen, nicht bei der Prüfung und auf keiner Verwaltungsseite. Regelpakete lädt es nie selbst; ein neues spielen Sie ein, indem Sie die Datei hochladen.

== Installation ==

1. Installieren Sie das Plugin über die Plugin-Seite von WordPress, oder laden Sie den Plugin-Ordner nach `/wp-content/plugins/`.
2. Aktivieren Sie das Plugin auf der Seite „Plugins“.
3. Öffnen Sie **eInvoice Validator -> VAMPIRE** und sehen Sie den Abschnitt „Systemprüfung“ an. Er zeigt, ob PHP-Fassung, Erweiterungen und Speicher ausreichen.
4. Fügen Sie den Kurzbefehl `[sppv_validator]` in eine Seite oder einen Beitrag ein.

PHP 8.2 ist eine harte Grenze. Unter einer älteren PHP-Fassung bleibt das Plugin untätig und sagt das im Verwaltungsbereich.

== Frequently Asked Questions ==

= Brauche ich Java oder ein weiteres Plugin? =

Nein. Die Prüfmaschine ist in PHP geschrieben und enthalten. Das Plugin braucht PHP 8.2 oder neuer mit den Erweiterungen `dom`, `libxml` und `bcmath`; 256 MB Speicher werden empfohlen.

= Wird die hochgeladene Rechnung irgendwo gespeichert? =

Nein. Die Rechnung wird während der Anfrage geprüft und nicht aufbewahrt. Das Plugin speichert weder Rechnungen noch Berichte.

= Welche Formate werden nicht geprüft? =

Die ZUGFeRD-Profile MINIMUM, BASIC WL und BASIC werden nur mit der Einstellung „Gegen jede Fassung prüfen“ geprüft. Die nationalen Varianten von Rumänien, Portugal, Belgien, Serbien, Italien, Kroatien und der Türkei sind nicht enthalten; eine solche Rechnung bekommt „Kein Ergebnis“ mit dem Grund.

= Wie lange dauert eine Prüfung? =

Die Zeit wächst mit der Zahl der Rechnungspositionen. Auf dem Entwicklungsserver gemessen braucht eine Rechnung mit 1 000 Positionen etwa 40 Sekunden und 174 MB Speicher.

= Kann ich begrenzen, wie viele Rechnungen ein Besucher hochladen darf? =

Ja. Die Einstellungsseite hat die Tabelle „Tägliche Uploads je Rolle“ mit einer Zeile für Gäste; 0 heisst kein Zugang.

= Kann ein Besucher viele Rechnungen auf einmal prüfen? =

Ja. Neben „Einzelprüfung“ hat die Seite den Reiter „Stapelprüfung“: Ein Besucher legt viele Rechnungen, einen Ordner oder eine ZIP-Datei ab, der Browser schickt sie einzeln nacheinander an die Website, und eine Übersicht nennt das Ergebnis jeder Datei, mit Filter, Sortierung, CSV-Download und einer druckbaren Zusammenfassung. Ein Klick auf eine Zeile zeigt den Bericht dieser Rechnung. Jede Rechnung zählt wie eine Einzelprüfung gegen das tägliche Limit, und der Stapel hält an, sobald es erreicht ist. Eine Seite, die nur die Einzelprüfung anbieten soll, verwendet `[sppv_validator batch="no"]`.

== Screenshots ==

1. Die Ablagefläche, die der Kurzbefehl `[sppv_validator]` auf eine Seite setzt.
2. Eine abgelehnte Rechnung: das Ergebnis, die Prüfschritte und jeder Fehler mit den betroffenen Feldern und den Werten aus der Rechnung.
3. Eine bestandene XRechnung mit einem Hinweis aus den Regeln der XRechnung.
4. Eine ZUGFeRD-PDF: Das Plugin nimmt das eingebettete XML aus der PDF und prüft es gegen die Regeln von EN 16931 für CII.

== Changelog ==

= 2.0.2 =
* Die beiden Download-Knöpfe des Regelberichts auf der Viewer-Seite zeigen ihren Text in der Sprache der Website.
* Fehlen nach einem Upload Dateien des Plugins oder sind sie unvollständig, bleibt das Plugin mit einem Hinweis im Backend aus, statt die ganze Website anzuhalten.

= 2.0.1 =
* Wartungsfassung ohne sichtbare Änderung.

= 2.0.0 =
* Der Knopf „Mit VAMPIRE prüfen“ im eInvoice Viewer kommt jetzt von diesem Plugin und braucht den Viewer 3.0.0; aktualisieren Sie zuerst den Viewer. Eine App kann diese Website als Quelle ihrer Prüfung eintragen ( /wp-json/einvoice/v1/validate/vampire ). Besucher nutzen diese Adresse im Rahmen der täglichen Uploads je Rolle, und jede Prüfung dort zählt mit.

= 1.14.3 =
* Ein Befund, der auf eine Gruppe zeigt, etwa den Verkäufer oder eine Rechnungsposition, zeigt keinen Hinweis auf die Zahl ihrer Unterelemente mehr.
* Mit dem Zusatz VAMPIRE JS und dem eInvoice Viewer ab 2.40.0 prüft auch der Knopf „Mit VAMPIRE prüfen“ im Viewer die Rechnung im Browser; die Rechnung verlässt den Rechner nicht.
* Neu: die Stapelprüfung. Besucher prüfen viele Rechnungen auf einmal, aus einem Ordner, einer ZIP-Datei oder einer Auswahl von Dateien, und bekommen eine Übersicht mit Filter, Sortierung, CSV-Download und einer druckbaren Zusammenfassung.
* Eine Prüfregel, die sich für eine Rechnung nicht auswerten lässt, bricht die Prüfung nicht mehr ab; der Bericht nennt sie als nicht auswertbar.
* Die Erklärung einer Regel zeigt bei Feldern, die mehrfach vorkommen, jetzt die Summe beziehungsweise den richtigen Wert statt des Werts der ersten Fundstelle.
* Die Erklärung eines Befunds zeigt jetzt die Werte, die die Regel tatsächlich verglichen hat, auch für Felder, die ihr Text nicht nennt; unter BR-CO-13 erscheint zum Beispiel die Summe der Nettobeträge der Positionen (BT-106) neben dem Gesamtbetrag ohne Umsatzsteuer.

= 1.14.2 =
* Die Regeln der EN 16931 folgen der Fassung 1.3.17. Die Regel BR-CO-09 nimmt das griechische Umsatzsteuer-Präfix EL bewusst weiter an, wie es ihr Regeltext erlaubt, und jeder Bericht sagt das.

= 1.14.1 =
* In der Rechnungsansicht des Berichts steht ein Hinweis an einer Zeile der Summen in einer eigenen Zeile unter dem Betrag, statt den Betrag aus der Tabelle zu schieben.

= 1.14.0 =
* Die Einstellungsseite steht jetzt unter dem Menüpunkt „eInvoice Validator“ im wp-admin, nicht mehr unter Einstellungen. Eine alte Adresse wird weitergeleitet.
* Im eInvoice Viewer ab Fassung 2.38.0 erscheint der Bericht in einem eigenen Reiter.

= 1.13.0 =
* Neu: Ist der kostenpflichtige Zusatz „VAMPIRE JS“ aktiv, prüft die Seite des Validators die Rechnung im Browser des Besuchers und zeigt denselben Bericht; die Rechnung wird dann nicht an die Website geschickt.

= 1.12.1 =
* Neu: „report.html herunterladen“ speichert den HTML-Bericht, den die Prüfmaschine schreibt, unverändert neben report.xml.
* Wortwahl: Bericht und Hinweise sprechen jetzt vom „Ergebnis“ einer Prüfung, und der KoSIT-Validator heisst überall der offizielle KoSIT-Validator.
* Ist die Prüfung gegen jede Fassung eingeschaltet, sind der Bericht unter der Tabelle und die heruntergeladene report.xml die der Fassung, die die Tabelle gewählt hat, bei ZUGFeRD und Factur-X.

= 1.12.0 =

* Der Bericht, seine Knöpfe und Meldungen folgen dem für die eInvoice-Werkzeuge gewählten Design-Kit, auch einem eigenen Kit.

= 1.11.1 =

* Der Hinweis unter dem Bericht behauptet nicht mehr, das Werkzeug prüfe selbst nichts.

= 1.11.0 =

* Ein Hinweis der Stufe „Information“ erscheint in der Belegansicht blau und nicht mehr wie ein Fehler.
* Das Plugin ist frei und steht unter der GNU General Public License, Version 2 oder später; es kommt über wordpress.org.
* Neue Regelpakete werden als ZIP-Datei eingespielt, ohne Shop-Konto.
* Neue Gestaltung „Kit S“ auf der Einstellungsseite: der Validator in den Farben der specialPress-Websites.

= 1.10.0 =

* Die Tagesgrenze lässt sich je Rolle einstellen, mit einer eigenen Zeile für Gäste; 0 heißt kein Zugang.
* Eine im eInvoice Viewer ab 2.35.0 geöffnete Rechnung bekommt den Knopf „Mit VAMPIRE prüfen“.
* Neu: Regelprofile aus dem Rule Studio lassen sich auf der Einstellungsseite installieren; ihre Befunde stehen im Bericht mit dem Namen des Profils.
* Die Antworten erscheinen in der Sprache der Seite, auch für angemeldete Benutzer mit einer anderen Profilsprache.

= 1.9.0 =

* Neue Einstellung „Positiver Prüfbericht“: Der Bericht führt jede Regel mit ihrem Ergebnis auf, auch die erfüllten, und lässt sich herunterladen und drucken.
* Neue Einstellung „Gegen jede Fassung prüfen“: ZUGFeRD- und Factur-X-Rechnungen werden von der neuesten Fassung abwärts geprüft, und eine Tabelle zeigt das Ergebnis je Fassung.
* Eine Rechnung, die eine Fassung nennt, die das Regelpaket noch nicht enthält, etwa XRechnung 4.0, bekommt „Kein Ergebnis“ mit dem Grund statt „Abgelehnt“.
* Neue Regelpakete lassen sich als ZIP aus dem Shop-Konto hochladen.
* Das Rechnungsbild markiert eine IBAN mit falschen Prüfziffern nicht mehr; der Prüfbericht ist davon nicht berührt.
* Das Paket nennt Herkunft und Lizenz jedes Regelwerks und enthält die Lizenztexte.

= 1.8.1 =
* Texte erscheinen in der Schrift des gewählten Design-Kits. Die Belegansicht markiert Felder, die eine Regel verletzen, mit einem übersetzten Hinweis unter dem Feld.

= 1.8.0 =
* Die Regeln von ZUGFeRD / Factur-X EXTENDED und EXTENDED-CTC-FR werden nur noch mit dem Zusatz „Extended Profiles“ erklärt. Lange CII-Rechnungen werden deutlich schneller geprüft; 400 Positionen brauchen etwa ein Drittel der bisherigen Zeit. Die Tagesgrenze zählt hinter einem Proxy die Adresse, die der Proxy angehängt hat.

= 1.7.0 bis 1.5.0 =
* Befunde zu Feldern einer Erweiterung zeigen mit Extended Profiles ihren Wert; das Design-Kit lässt sich wählen; der Bericht erscheint in der Ansicht des Validation Explorers.
