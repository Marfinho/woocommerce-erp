/**
 * Pfeile für die Eventreihe.
 *
 * Das Blättern selbst macht CSS (scroll-snap). Dieses Skript blendet nur
 * die Pfeile ein und schaltet sie ab, wenn es in die Richtung nicht mehr
 * weitergeht. Ohne das Skript bleibt die Reihe wischbar und mit den
 * Pfeiltasten bedienbar - deshalb stehen die Knöpfe im Markup auf hidden.
 */
(function () {
	'use strict';

	function einrichten(widget) {
		var spur = widget.querySelector('.lw16-events-widget__spur');
		var zurueck = widget.querySelector('.lw16-events-widget__pfeil--zurueck');
		var vor = widget.querySelector('.lw16-events-widget__pfeil--vor');

		if (!spur || !zurueck || !vor) {
			return;
		}

		zurueck.hidden = false;
		vor.hidden = false;

		function schrittweite() {
			var karte = spur.querySelector('.lw16-events-widget__karte');
			// Eine Karte je Seite: Kartenbreite plus Abstand.
			return karte ? karte.getBoundingClientRect().width + 16 : spur.clientWidth;
		}

		function aktualisieren() {
			// Ein Pixel Toleranz: Bei gebrochenen Breiten erreicht der
			// Rollwert das Maximum sonst nie ganz, und der Pfeil bliebe
			// bis zuletzt aktiv.
			var maximum = spur.scrollWidth - spur.clientWidth;
			zurueck.disabled = spur.scrollLeft <= 1;
			vor.disabled = spur.scrollLeft >= maximum - 1;
		}

		zurueck.addEventListener('click', function () {
			spur.scrollBy({ left: -schrittweite(), behavior: 'smooth' });
		});

		vor.addEventListener('click', function () {
			spur.scrollBy({ left: schrittweite(), behavior: 'smooth' });
		});

		spur.addEventListener('scroll', aktualisieren, { passive: true });
		window.addEventListener('resize', aktualisieren);

		aktualisieren();
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
