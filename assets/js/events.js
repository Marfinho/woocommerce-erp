/**
 * Bedienung der Eventreihe: Pfeile, Punkte und Selbstlauf.
 *
 * Das Blättern selbst macht CSS (scroll-snap). Dieses Skript ergänzt die
 * Bedienelemente und den Selbstlauf. Ohne das Skript bleibt die Reihe
 * wischbar und mit den Pfeiltasten bedienbar - deshalb stehen Pfeile,
 * Punkte und Pausenknopf im Markup auf hidden.
 */
(function () {
	'use strict';

	// Bewegung, die von allein losgeht, ist fuer manche Menschen ein
	// Problem und nicht nur Geschmackssache. Wer sie abbestellt hat,
	// bekommt die Reihe still - der Pausenknopf entfaellt dann auch.
	var wenigerBewegung = window.matchMedia
		? window.matchMedia('(prefers-reduced-motion: reduce)')
		: { matches: false };

	function einrichten(widget) {
		var spur = widget.querySelector('.lw16-events-widget__spur');
		if (!spur) {
			return;
		}

		var karten = spur.querySelectorAll('.lw16-events-widget__karte');
		if (karten.length < 2) {
			return;
		}

		var zurueck = widget.querySelector('.lw16-events-widget__pfeil--zurueck');
		var vor = widget.querySelector('.lw16-events-widget__pfeil--vor');
		var steuerung = widget.querySelector('.lw16-events-widget__steuerung');
		var pauseKnopf = widget.querySelector('.lw16-events-widget__pause');
		var punkte = widget.querySelectorAll('.lw16-events-widget__punkt');

		var selbstlauf = widget.dataset.autoplay === '1' && !wenigerBewegung.matches;
		var intervall = parseInt(widget.dataset.intervall, 10) || 7000;

		var timer = null;
		var vonHandPausiert = false;
		var zeigerDrauf = false;
		var fokusDrin = false;

		if (zurueck && vor) {
			zurueck.hidden = false;
			vor.hidden = false;
		}

		if (steuerung) {
			steuerung.hidden = false;
		}

		// Ohne Selbstlauf braucht es keinen Pausenknopf - er bliebe ohne
		// Wirkung stehen.
		if (pauseKnopf && !selbstlauf) {
			pauseKnopf.hidden = true;
		}

		function schrittweite() {
			// Eine Karte je Seite: Kartenbreite plus Abstand.
			return karten[0].getBoundingClientRect().width + 16;
		}

		function aktuellerIndex() {
			var breite = schrittweite();
			return breite > 0 ? Math.round(spur.scrollLeft / breite) : 0;
		}

		function zuIndex(index) {
			var ziel = Math.max(0, Math.min(karten.length - 1, index));
			spur.scrollTo({
				left: ziel * schrittweite(),
				// Wer Bewegung abbestellt hat, bekommt auch beim Klick auf
				// Pfeil oder Punkt keine weiche Fahrt, sondern den Sprung.
				behavior: wenigerBewegung.matches ? 'auto' : 'smooth'
			});
		}

		function aktualisieren() {
			// Ein Pixel Toleranz: Bei gebrochenen Breiten erreicht der
			// Rollwert das Maximum sonst nie ganz, und der Pfeil bliebe
			// bis zuletzt aktiv.
			var maximum = spur.scrollWidth - spur.clientWidth;

			if (zurueck && vor) {
				zurueck.disabled = spur.scrollLeft <= 1;
				vor.disabled = spur.scrollLeft >= maximum - 1;
			}

			var jetzt = aktuellerIndex();
			for (var i = 0; i < punkte.length; i++) {
				punkte[i].setAttribute('aria-selected', i === jetzt ? 'true' : 'false');
			}
		}

		/* --- Selbstlauf ------------------------------------------------ */

		function darfLaufen() {
			return selbstlauf && !vonHandPausiert && !zeigerDrauf && !fokusDrin && !document.hidden;
		}

		function weiter() {
			var naechster = aktuellerIndex() + 1;
			// Am Ende wieder von vorn, sonst bliebe die Reihe stehen.
			zuIndex(naechster >= karten.length ? 0 : naechster);
		}

		function starten() {
			stoppen();
			if (darfLaufen()) {
				timer = window.setInterval(weiter, intervall);
			}
		}

		function stoppen() {
			if (timer !== null) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		function knopfBeschriften() {
			if (!pauseKnopf) {
				return;
			}
			var text = vonHandPausiert
				? pauseKnopf.dataset.pausiert
				: pauseKnopf.dataset.laeuft;

			pauseKnopf.setAttribute('aria-pressed', vonHandPausiert ? 'true' : 'false');
			pauseKnopf.setAttribute('aria-label', text);
			pauseKnopf.title = text;
			widget.classList.toggle('is-pausiert', vonHandPausiert);
		}

		if (pauseKnopf && selbstlauf) {
			pauseKnopf.addEventListener('click', function () {
				vonHandPausiert = !vonHandPausiert;
				knopfBeschriften();
				starten();
			});
			knopfBeschriften();
		}

		// Anhalten, solange jemand hinschaut oder mit der Tastatur drin ist.
		// Von Hand pausiert bleibt pausiert - das Zeigen der Maus hebt eine
		// bewusste Entscheidung nicht auf.
		widget.addEventListener('mouseenter', function () {
			zeigerDrauf = true;
			stoppen();
		});
		widget.addEventListener('mouseleave', function () {
			zeigerDrauf = false;
			starten();
		});
		widget.addEventListener('focusin', function () {
			fokusDrin = true;
			stoppen();
		});
		widget.addEventListener('focusout', function () {
			fokusDrin = false;
			starten();
		});

		// Im Hintergrundtab weiterzublaettern kostet nur Rechenzeit.
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				stoppen();
			} else {
				starten();
			}
		});

		/* --- Bedienelemente -------------------------------------------- */

		if (zurueck && vor) {
			zurueck.addEventListener('click', function () {
				zuIndex(aktuellerIndex() - 1);
			});
			vor.addEventListener('click', function () {
				zuIndex(aktuellerIndex() + 1);
			});
		}

		for (var p = 0; p < punkte.length; p++) {
			punkte[p].addEventListener('click', function (e) {
				zuIndex(parseInt(e.currentTarget.dataset.index, 10) || 0);
			});
		}

		spur.addEventListener('scroll', aktualisieren, { passive: true });
		window.addEventListener('resize', aktualisieren);

		// Beim Wischen soll der Selbstlauf nicht dazwischenfunken.
		spur.addEventListener('touchstart', stoppen, { passive: true });
		spur.addEventListener('touchend', starten, { passive: true });

		aktualisieren();
		starten();
	}

	function start() {
		var widgets = document.querySelectorAll('.lw16-events-widget');
		for (var i = 0; i < widgets.length; i++) {
			einrichten(widgets[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
