/**
 * Akibara Hero — Gemini Images v2
 * + Mouse parallax (image follows cursor subtly)
 * + Scroll parallax
 * + Prefetch /tienda/ on hover
 * + GPU fix
 * + Reactive reduced motion
 */
(function () {
  "use strict";

  var hero    = document.querySelector(".aki-hero");
  var img     = document.querySelector(".aki-hero__img");
  var hitbox  = document.querySelector(".aki-hero__cta-hitbox");

  if (!hero) return;

  var shopUrl    = hero.getAttribute("data-shop-url") || "/tienda/";
  var motionOk   = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var hoverDevice = window.matchMedia("(hover: hover) and (pointer: fine)").matches;

  // 1. Chrome <picture> first-paint GPU fix
  if (img) {
    requestAnimationFrame(function () {
      img.style.willChange = "transform";
    });
  }

  // 2. Click-through — toda la sección va a tienda
  hero.addEventListener("click", function (e) {
    if (e.target.closest("a, button")) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    window.location.href = shopUrl;
  });

  // 3. Prefetch /tienda/ on first hover (performance hint)
  var prefetched = false;
  hero.addEventListener("mouseenter", function () {
    if (prefetched) return;
    prefetched = true;
    var link = document.createElement("link");
    link.rel  = "prefetch";
    link.href = shopUrl;
    document.head.appendChild(link);
  }, { once: true });

  if (!motionOk || !img) return;

  // 4. Mouse parallax — imagen sigue el cursor suavemente
  if (hoverDevice) {
    var mouseX = 0, mouseY = 0;
    var curX   = 0, curY   = 0;
    var heroRect = hero.getBoundingClientRect();
    var rafMouse = null;
    var inHero   = false;

    hero.addEventListener("mouseenter", function () {
      inHero   = true;
      heroRect = hero.getBoundingClientRect();
      if (!rafMouse) rafMouse = requestAnimationFrame(animateMouse);
    });

    hero.addEventListener("mouseleave", function () {
      inHero = false;
      // Ease back to center
      var ease = function () {
        curX += (0 - curX) * 0.08;
        curY += (0 - curY) * 0.08;
        applyTransform(curX, curY, scrollShift);
        if (Math.abs(curX) > 0.05 || Math.abs(curY) > 0.05) {
          requestAnimationFrame(ease);
        } else {
          curX = 0; curY = 0;
          applyTransform(0, 0, scrollShift);
        }
      };
      requestAnimationFrame(ease);
    });

    hero.addEventListener("mousemove", function (e) {
      heroRect = hero.getBoundingClientRect();
      // Normalize -1 to 1
      mouseX = ((e.clientX - heroRect.left) / heroRect.width  - 0.5) * 2;
      mouseY = ((e.clientY - heroRect.top)  / heroRect.height - 0.5) * 2;
    });

    function animateMouse() {
      if (inHero) {
        // Max shift: 8px horizontal, 5px vertical
        var targetX = mouseX * -8;
        var targetY = mouseY * -5;
        curX += (targetX - curX) * 0.06;
        curY += (targetY - curY) * 0.06;
        applyTransform(curX, curY, scrollShift);
        rafMouse = requestAnimationFrame(animateMouse);
      } else {
        rafMouse = null;
      }
    }
  }

  // 5. Scroll parallax
  var scrollShift = 0;
  var heroH       = hero.offsetHeight;
  var maxShift    = heroH * 0.04;
  var ticking     = false;

  if (typeof ResizeObserver !== "undefined") {
    new ResizeObserver(function (entries) {
      heroH    = entries[0].contentRect.height;
      maxShift = heroH * 0.04;
    }).observe(hero);
  }

  function applyTransform(mx, my, sy) {
    if (!img) return;
    img.style.transform =
      "translateZ(0) scale(1.0) translate(" + mx + "px, " + (my + sy) + "px)";
  }

  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      var rect = hero.getBoundingClientRect();
      if (rect.bottom > 0 && rect.top < window.innerHeight) {
        var progress = Math.min(Math.max(-rect.top / heroH, 0), 1);
        scrollShift  = progress * maxShift;
        applyTransform(curX || 0, curY || 0, scrollShift);
      }
      ticking = false;
    });
  }

  var io = new IntersectionObserver(function (entries) {
    if (entries[0].isIntersecting) {
      window.addEventListener("scroll", onScroll, { passive: true });
    } else {
      window.removeEventListener("scroll", onScroll);
    }
  }, { threshold: 0 });

  io.observe(hero);

  // 6. Reactive reduced motion
  window.matchMedia("(prefers-reduced-motion: reduce)").addEventListener("change", function (e) {
    if (e.matches && img) {
      window.removeEventListener("scroll", onScroll);
      img.style.transform = "translateZ(0) scale(1)";
      img.style.animation = "none";
    }
  });

})();
