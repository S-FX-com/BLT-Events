/**
 * BLT Events - Single event page.
 *
 * A small lightbox for sponsor logos without a sponsor link. Links marked
 * data-blt-lightbox="group" open their full-size image in a native <dialog>:
 * Escape or a click outside the image closes it, the arrow keys step through
 * the group, and focus returns to the logo that opened it. Browsers without
 * <dialog> just follow the link to the image.
 *
 * Strings come from bltEventsLightbox (localized in PHP).
 */
(function () {
	"use strict";

	var i18n = window.bltEventsLightbox || {};
	var dialog = null;
	var img = null;
	var caption = null;
	var prevBtn = null;
	var nextBtn = null;
	var group = [];
	var index = 0;
	var opener = null;

	function button(cls, label, text) {
		var el = document.createElement("button");
		el.type = "button";
		el.className = "blt-lightbox__btn " + cls;
		el.setAttribute("aria-label", label);
		el.textContent = text;
		return el;
	}

	function build() {
		dialog = document.createElement("dialog");
		dialog.className = "blt-lightbox";

		var figure = document.createElement("figure");
		figure.className = "blt-lightbox__figure";
		img = document.createElement("img");
		img.className = "blt-lightbox__img";
		img.alt = "";
		caption = document.createElement("figcaption");
		caption.className = "blt-lightbox__caption";
		figure.appendChild(img);
		figure.appendChild(caption);

		var close = button("blt-lightbox__close", i18n.close || "Close", "×");
		prevBtn = button("blt-lightbox__prev", i18n.prev || "Previous image", "‹");
		nextBtn = button("blt-lightbox__next", i18n.next || "Next image", "›");

		dialog.appendChild(figure);
		dialog.appendChild(prevBtn);
		dialog.appendChild(nextBtn);
		dialog.appendChild(close);
		document.body.appendChild(dialog);

		close.addEventListener("click", function () {
			dialog.close();
		});
		prevBtn.addEventListener("click", function () {
			show(index - 1);
		});
		nextBtn.addEventListener("click", function () {
			show(index + 1);
		});

		// A click on the backdrop lands on the <dialog> itself.
		dialog.addEventListener("click", function (e) {
			if (e.target === dialog) {
				dialog.close();
			}
		});

		dialog.addEventListener("keydown", function (e) {
			if (e.key === "ArrowLeft") {
				e.preventDefault();
				show(index - 1);
			} else if (e.key === "ArrowRight") {
				e.preventDefault();
				show(index + 1);
			}
		});

		dialog.addEventListener("close", function () {
			img.removeAttribute("src");
			if (opener && typeof opener.focus === "function") {
				opener.focus();
			}
		});
	}

	function show(i) {
		if (!group.length) {
			return;
		}
		index = (i + group.length) % group.length;

		var link = group[index];
		var thumb = link.querySelector("img");
		var alt = thumb ? thumb.getAttribute("alt") || "" : "";

		img.src = link.getAttribute("href");
		img.alt = alt;
		caption.textContent = alt;
		caption.hidden = !alt;

		var many = group.length > 1;
		prevBtn.hidden = !many;
		nextBtn.hidden = !many;
	}

	document.addEventListener("click", function (e) {
		var link = e.target && e.target.closest ? e.target.closest("a[data-blt-lightbox]") : null;
		if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
			return;
		}
		if (typeof window.HTMLDialogElement !== "function") {
			return;
		}

		e.preventDefault();
		if (!dialog) {
			build();
		}

		var name = link.getAttribute("data-blt-lightbox");
		group = Array.prototype.filter.call(document.querySelectorAll("a[data-blt-lightbox]"), function (a) {
			return a.getAttribute("data-blt-lightbox") === name;
		});
		opener = link;
		show(group.indexOf(link));
		dialog.showModal();
	});
})();
