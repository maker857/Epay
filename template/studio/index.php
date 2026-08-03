<?php
if (!defined('IN_CRONLITE')) exit();

$studioTitle = 'Northstar Studio';
$studioDescription = 'A small independent team shaping clear digital experiences, identities, and useful products.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo htmlspecialchars($studioDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($studioTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo STATIC_ROOT; ?>css/studio.css">
    <script>document.documentElement.classList.add('motion-ready');</script>
    <script defer src="<?php echo STATIC_ROOT; ?>js/studio.js"></script>
</head>
<body>
    <div class="studio-shell">
        <header class="studio-nav" data-load-item style="--load-index: 0">
            <a class="wordmark" href="/" aria-label="Northstar Studio home" data-magnetic>
                <span class="wordmark-mark">N</span>
                <span>Northstar<br><em>Independent studio</em></span>
            </a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="#work">Selected work</a>
                <a href="#approach">Approach</a>
                <a class="nav-contact" href="#contact" data-magnetic>Start a conversation <span aria-hidden="true">&rarr;</span></a>
            </nav>
        </header>

        <main>
            <section class="hero" aria-labelledby="hero-title">
                <div class="hero-copy">
                    <p class="eyebrow" data-load-item style="--load-index: 1">Independent creative practice &middot; 2026</p>
                    <h1 id="hero-title" aria-label="Good ideas need room to move.">
                        <span class="title-line"><span data-load-item style="--load-index: 2">Good ideas need</span></span>
                        <span class="title-line"><i data-load-item style="--load-index: 3">room to move.</i></span>
                    </h1>
                    <p class="hero-intro" data-load-item style="--load-index: 4">We turn sharp points of view into brands, digital products, and experiences that stay useful long after launch.</p>
                    <a class="text-link" href="#work" data-load-item data-magnetic style="--load-index: 5">View selected work <span aria-hidden="true">&rarr;</span></a>
                </div>
                <div class="hero-art" aria-label="Abstract illustration" data-load-item data-parallax style="--load-index: 3">
                    <div class="art-grid" aria-hidden="true"></div>
                    <div class="art-halo" aria-hidden="true"></div>
                    <div class="art-note">BUILD / TEST / REFINE</div>
                    <div class="art-image-wrap">
                        <img src="/template/index9/assets/picture/bg2.png" alt="Abstract product and creative tools illustration">
                    </div>
                    <div class="art-orbit art-orbit-one" aria-hidden="true"></div>
                    <div class="art-orbit art-orbit-two" aria-hidden="true"></div>
                    <div class="art-caption"><strong>01</strong><span>Ideas in motion<br>since 2018</span></div>
                </div>
            </section>

            <section class="signal-row" aria-label="Studio summary" data-reveal-group>
                <p data-reveal-item>Strategy, identity, and digital craft for teams that care about the details.</p>
                <div class="signal-stat" data-reveal-item><strong>14</strong><span>brands brought<br>into focus</span></div>
                <div class="signal-stat" data-reveal-item><strong>06</strong><span>people, one<br>shared practice</span></div>
            </section>

            <section class="work-section" id="work" aria-labelledby="work-title">
                <div class="section-heading" data-reveal>
                    <p class="eyebrow">A few things we are proud of</p>
                    <h2 id="work-title">Selected<br><i>work</i></h2>
                </div>
                <div class="project-grid" data-reveal-group>
                    <article class="project project-wide project-amber" data-reveal-item data-tilt>
                        <div class="project-meta"><span>Identity system</span><span>2025</span></div>
                        <div class="project-visual visual-amber"><span>COMMON<br>GROUND</span><b>CG</b></div>
                        <h3>Common Ground</h3>
                        <p>A warmer, clearer identity for a network of independent makers.</p>
                    </article>
                    <article class="project project-blue" data-reveal-item data-tilt>
                        <div class="project-meta"><span>Digital product</span><span>2024</span></div>
                        <div class="project-visual visual-blue"><span class="orbit orbit-one"></span><span class="orbit orbit-two"></span><b>FIELD<br>NOTES</b></div>
                        <h3>Field Notes</h3>
                        <p>A calm workspace for turning research into decisions.</p>
                    </article>
                    <article class="project project-lime" data-reveal-item data-tilt>
                        <div class="project-meta"><span>Campaign</span><span>2024</span></div>
                        <div class="project-visual visual-lime"><span>MAKE<br>SPACE</span><b aria-hidden="true">&rarr;</b></div>
                        <h3>Make Space</h3>
                        <p>A launch campaign for a new kind of creative residency.</p>
                    </article>
                </div>
            </section>

            <section class="approach-section" id="approach" aria-labelledby="approach-title">
                <div class="approach-intro" data-reveal>
                    <h2 id="approach-title">Less theatre.<br><i>More traction.</i></h2>
                </div>
                <div class="approach-list" data-reveal-group>
                    <div class="approach-item" data-reveal-item><span>01</span><div><h3>Find the signal</h3><p>We get close to the problem, ask better questions, and make the shape of the opportunity visible.</p></div></div>
                    <div class="approach-item" data-reveal-item><span>02</span><div><h3>Make it tangible</h3><p>Ideas become language, systems, prototypes, and small moments people can actually react to.</p></div></div>
                    <div class="approach-item" data-reveal-item><span>03</span><div><h3>Leave a useful mark</h3><p>Everything we make is built to travel well: clear enough to use, flexible enough to grow.</p></div></div>
                </div>
            </section>

            <section class="contact-section" id="contact" aria-labelledby="contact-title" data-reveal>
                <h2 id="contact-title">Let's make<br><i>something useful.</i></h2>
                <a class="contact-button" href="mailto:hello@example.com" data-magnetic>hello@example.com <span aria-hidden="true">&rarr;</span></a>
            </section>
        </main>

        <footer class="studio-footer" data-reveal-group>
            <span data-reveal-item>Northstar Studio</span>
            <span data-reveal-item>Independent practice for thoughtful teams</span>
            <a href="/user/" data-reveal-item>Private portal &rarr;</a>
        </footer>
    </div>
</body>
</html>
