(() => {
    'use strict';

    const root = document.documentElement;
    root.classList.add('motion-ready');

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const precisePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
    const abortController = new AbortController();
    const listenerOptions = { signal: abortController.signal };
    let revealObserver = null;

    const revealImmediately = () => {
        document.querySelectorAll('[data-reveal], [data-reveal-item]').forEach((element) => {
            element.classList.add('is-visible');
        });
    };

    if (reducedMotion.matches) {
        root.classList.add('motion-reduced');
        revealImmediately();
        return;
    }

    const revealTargets = Array.from(document.querySelectorAll('[data-reveal], [data-reveal-item]'));
    document.querySelectorAll('[data-reveal-group]').forEach((group) => {
        group.querySelectorAll('[data-reveal-item]').forEach((item, index) => {
            item.style.setProperty('--reveal-index', String(index));
        });
    });

    if ('IntersectionObserver' in window) {
        revealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, {
            rootMargin: '0px 0px -10% 0px',
            threshold: 0.16,
        });

        revealTargets.forEach((target) => revealObserver.observe(target));
    } else {
        revealImmediately();
    }

    const scheduleFrame = (element, update) => {
        if (element.dataset.motionFrame) return;
        element.dataset.motionFrame = String(requestAnimationFrame(() => {
            delete element.dataset.motionFrame;
            update();
        }));
    };

    const resetPointerVariables = (element) => {
        element.style.setProperty('--tilt-x', '0deg');
        element.style.setProperty('--tilt-y', '0deg');
        element.style.setProperty('--pointer-x', '50%');
        element.style.setProperty('--pointer-y', '50%');
        element.style.setProperty('--parallax-x', '0px');
        element.style.setProperty('--parallax-y', '0px');
        element.style.setProperty('--magnetic-x', '0px');
        element.style.setProperty('--magnetic-y', '0px');
        element.classList.remove('is-interacting');
    };

    const bindTilt = (element) => {
        element.addEventListener('pointermove', (event) => {
            scheduleFrame(element, () => {
                const bounds = element.getBoundingClientRect();
                const x = (event.clientX - bounds.left) / bounds.width;
                const y = (event.clientY - bounds.top) / bounds.height;
                const tiltY = (x - 0.5) * 4.8;
                const tiltX = (0.5 - y) * 4.8;

                element.style.setProperty('--tilt-x', `${tiltX.toFixed(2)}deg`);
                element.style.setProperty('--tilt-y', `${tiltY.toFixed(2)}deg`);
                element.style.setProperty('--pointer-x', `${(x * 100).toFixed(1)}%`);
                element.style.setProperty('--pointer-y', `${(y * 100).toFixed(1)}%`);
                element.classList.add('is-interacting');
            });
        }, listenerOptions);

        element.addEventListener('pointerleave', () => resetPointerVariables(element), listenerOptions);
    };

    const bindParallax = (element) => {
        element.addEventListener('pointermove', (event) => {
            scheduleFrame(element, () => {
                const bounds = element.getBoundingClientRect();
                const x = (event.clientX - bounds.left) / bounds.width - 0.5;
                const y = (event.clientY - bounds.top) / bounds.height - 0.5;

                element.style.setProperty('--parallax-x', `${(x * 28).toFixed(2)}px`);
                element.style.setProperty('--parallax-y', `${(y * 28).toFixed(2)}px`);
                element.style.setProperty('--pointer-x', `${((x + 0.5) * 100).toFixed(1)}%`);
                element.style.setProperty('--pointer-y', `${((y + 0.5) * 100).toFixed(1)}%`);
                element.classList.add('is-interacting');
            });
        }, listenerOptions);

        element.addEventListener('pointerleave', () => resetPointerVariables(element), listenerOptions);
    };

    const bindMagnetic = (element) => {
        element.addEventListener('pointermove', (event) => {
            scheduleFrame(element, () => {
                const bounds = element.getBoundingClientRect();
                const x = ((event.clientX - bounds.left) / bounds.width - 0.5) * 12;
                const y = ((event.clientY - bounds.top) / bounds.height - 0.5) * 8;
                element.style.setProperty('--magnetic-x', `${x.toFixed(2)}px`);
                element.style.setProperty('--magnetic-y', `${y.toFixed(2)}px`);
            });
        }, listenerOptions);

        element.addEventListener('pointerleave', () => resetPointerVariables(element), listenerOptions);
    };

    if (precisePointer.matches) {
        document.querySelectorAll('[data-tilt]').forEach(bindTilt);
        document.querySelectorAll('[data-parallax]').forEach(bindParallax);
        document.querySelectorAll('[data-magnetic]').forEach(bindMagnetic);
    }

    window.addEventListener('pagehide', () => {
        abortController.abort();
        if (revealObserver) revealObserver.disconnect();
    }, { once: true });
})();

