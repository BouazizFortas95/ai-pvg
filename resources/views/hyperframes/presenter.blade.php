<template>
<style>
  /* ── Presenter sub-composition ── */
  #pvg-presenter { position: absolute; inset: 0; overflow: hidden; }

  /* AI disclosure badge */
  #pvg-badge {
    position: absolute;
    bottom: 18px; left: 50%; transform: translateX(-50%);
    background: rgba(0,0,0,0.72);
    color: #fff;
    font-family: system-ui, sans-serif;
    font-size: 11px;
    letter-spacing: 0.08em;
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.25);
    white-space: nowrap;
    pointer-events: none;
    z-index: 100;
  }

  /* ── Broadcast Tools (M3 non-identity variation) ── */
  .pvg-tool-lower_third_pill {
    position: absolute;
    bottom: 54px; left: 24px;
    display: flex; align-items: center; gap: 8px;
    background: rgba(10, 15, 26, 0.88);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-left: 3px solid {{ $accentColor ?? '#3B82F6' }};
    padding: 6px 14px;
    border-radius: 6px;
    font-family: system-ui, -apple-system, sans-serif;
    color: #fff;
    box-shadow: 0 10px 24px rgba(0,0,0,0.45);
    z-index: 90;
  }
  .pvg-tool-breaking_ticker {
    position: absolute;
    bottom: 48px; left: 0; right: 0;
    display: flex; align-items: center; gap: 10px;
    background: linear-gradient(90deg, rgba(15,23,42,0.96), rgba(30,41,59,0.92));
    border-top: 1px solid {{ $accentColor ?? '#3B82F6' }};
    border-bottom: 1px solid rgba(255,255,255,0.10);
    padding: 8px 18px;
    font-family: system-ui, sans-serif;
    color: #fff;
    z-index: 90;
  }
  .pvg-tool-corner_topic_card {
    position: absolute;
    top: 28px; right: 24px;
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(255,255,255,0.15);
    padding: 8px 12px;
    border-radius: 8px;
    font-family: system-ui, sans-serif;
    color: #fff;
    z-index: 90;
  }
  .pvg-tool-side_stat_pill {
    position: absolute;
    top: 120px; left: 20px;
    background: rgba(10, 15, 25, 0.88);
    border: 1px solid {{ $accentColor ?? '#3B82F6' }};
    padding: 6px 12px;
    border-radius: 20px;
    font-family: system-ui, sans-serif;
    color: #fff;
    z-index: 90;
  }
  .pvg-tag {
    font-size: 10px; font-weight: 800; letter-spacing: 0.12em;
    color: {{ $accentColor ?? '#3B82F6' }}; text-transform: uppercase;
  }
  .pvg-label {
    font-size: 12px; font-weight: 600; letter-spacing: 0.02em;
    color: rgba(255,255,255,0.92);
  }
</style>

<div id="pvg-presenter"
  data-composition-id="pvg-presenter"
  data-width="{{ $width ?? 1080 }}"
  data-height="{{ $height ?? 1920 }}"
  data-duration="{{ $audioDuration ?? 10.0 }}"
  data-sfx-cue="{{ $sfxCue ?? 'whoosh' }}"
  data-set-style="{{ $setStyle ?? 'gradient_minimal' }}">
  {{-- SVG Presenter Rig --}}
  <svg id="pvg-rig"
    viewBox="0 0 540 960"
    xmlns="http://www.w3.org/2000/svg"
    style="width:100%;height:100%;display:block;">

    {{-- ── Background gradient ── --}}
    <defs>
      <radialGradient id="bg-grad" cx="50%" cy="60%" r="70%">
        <stop offset="0%"   stop-color="{{ $bgLight }}"/>
        <stop offset="100%" stop-color="{{ $bgDark }}"/>
      </radialGradient>
      <radialGradient id="skin-grad" cx="50%" cy="38%" r="58%">
        <stop offset="0%"   stop-color="{{ $skinLight }}"/>
        <stop offset="100%" stop-color="{{ $skinTone }}"/>
      </radialGradient>
      @if (($setStyle ?? '') === 'dark_emitter')
      <radialGradient id="emitter-beam" cx="50%" cy="75%" r="55%">
        <stop offset="0%" stop-color="{{ $accentColor ?? '#3B82F6' }}" stop-opacity="0.32"/>
        <stop offset="100%" stop-color="{{ $bgDark }}" stop-opacity="0"/>
      </radialGradient>
      @endif
      <!-- Mouth shape paths for 6 visemes -->
      <!-- closed -->  <path id="vis-closed"  d="M 230 590 Q 270 593 310 590" fill="none"/>
      <!-- small  -->  <path id="vis-small"   d="M 238 588 Q 270 598 302 588 Q 270 606 238 588Z" fill="{{ $skinTone }}"/>
      <!-- wide   -->  <path id="vis-wide"    d="M 225 585 Q 270 620 315 585 Q 270 645 225 585Z" fill="{{ $skinTone }}"/>
      <!-- round  -->  <path id="vis-round"   d="M 245 585 Q 270 610 295 585 Q 270 628 245 585Z" fill="{{ $skinTone }}"/>
      <!-- fv     -->  <path id="vis-fv"      d="M 235 587 Q 270 597 305 587 Q 270 607 235 587Z" fill="{{ $skinTone }}"/>
      <!-- th     -->  <path id="vis-th"      d="M 240 588 Q 270 600 300 588 Q 270 612 240 588Z" fill="{{ $skinTone }}"/>
    </defs>

    {{-- Background --}}
    <rect id="pvg-bg" x="0" y="0" width="540" height="960" fill="url(#bg-grad)"/>

    {{-- Dynamic Set Accents (M3) --}}
    @if (($setStyle ?? '') === 'cyber_grid')
      <g id="pvg-set-grid" stroke="{{ $accentColor ?? '#3B82F6' }}" stroke-width="0.8" opacity="0.20">
        <line x1="0" y1="280" x2="540" y2="280"/>
        <line x1="0" y1="440" x2="540" y2="440"/>
        <line x1="0" y1="600" x2="540" y2="600"/>
        <line x1="0" y1="760" x2="540" y2="760"/>
        <line x1="90" y1="0" x2="90" y2="960"/>
        <line x1="270" y1="0" x2="270" y2="960"/>
        <line x1="450" y1="0" x2="450" y2="960"/>
      </g>
    @elseif (($setStyle ?? '') === 'dark_emitter')
      <rect id="pvg-set-emitter" x="0" y="240" width="540" height="720" fill="url(#emitter-beam)"/>
    @elseif (($setStyle ?? '') === 'modern_loft')
      <circle cx="100" cy="220" r="140" fill="{{ $accentColor ?? '#3B82F6' }}" opacity="0.08"/>
      <circle cx="440" cy="340" r="110" fill="{{ $accentColor ?? '#3B82F6' }}" opacity="0.06"/>
    @elseif (($setStyle ?? '') === 'newsroom_blur')
      <rect x="30" y="140" width="480" height="180" rx="16" fill="rgba(255,255,255,0.03)" stroke="rgba(255,255,255,0.08)"/>
    @endif

    {{-- Torso / Shoulders --}}
    <g id="pvg-torso">
      {{-- Shoulders --}}
      <ellipse id="pvg-shoulders" cx="270" cy="870" rx="190" ry="120" fill="{{ $outfitColor }}"/>
      {{-- Neck --}}
      <rect id="pvg-neck" x="245" y="730" width="50" height="90" rx="12" fill="url(#skin-grad)"/>
      {{-- Collar / outfit top --}}
      <path id="pvg-collar" d="M 200 820 Q 270 780 340 820 L 460 960 L 80 960 Z" fill="{{ $outfitDark }}"/>
    </g>

    {{-- Head --}}
    <g id="pvg-head">
      {{-- Head shape ({{ $headShape }}) --}}
      @if ($headShape === 'round')
        <ellipse id="pvg-face" cx="270" cy="500" rx="135" ry="145" fill="url(#skin-grad)"/>
      @elseif ($headShape === 'square')
        <rect id="pvg-face" x="135" y="355" width="270" height="290" rx="40" fill="url(#skin-grad)"/>
      @elseif ($headShape === 'heart')
        <path id="pvg-face" d="M 270 640 Q 130 580 135 470 Q 135 355 270 365 Q 405 355 405 470 Q 410 580 270 640Z" fill="url(#skin-grad)"/>
      @elseif ($headShape === 'diamond')
        <polygon id="pvg-face" points="270,355 405,500 270,645 135,500" fill="url(#skin-grad)"/>
      @else {{-- oval (default) --}}
        <ellipse id="pvg-face" cx="270" cy="500" rx="128" ry="150" fill="url(#skin-grad)"/>
      @endif

      {{-- Hair --}}
      <g id="pvg-hair" fill="{{ $hairColor }}">
        @if (in_array($hairStyle, ['short_neat', 'buzzcut']))
          <ellipse cx="270" cy="430" rx="132" ry="90"/>
          <rect x="138" y="420" width="264" height="50" rx="4"/>
        @elseif ($hairStyle === 'medium_wavy')
          <path d="M 138 480 Q 120 390 160 360 Q 210 330 270 340 Q 330 330 380 360 Q 420 390 402 480 Q 390 420 350 390 Q 310 360 270 365 Q 230 360 190 390 Q 150 420 138 480Z"/>
          <path d="M 136 490 Q 118 440 130 480 Q 135 500 138 490Z"/>
          <path d="M 404 490 Q 422 440 410 480 Q 405 500 404 490Z"/>
        @elseif ($hairStyle === 'long_straight')
          <rect x="134" y="370" width="30" height="180" rx="12"/>
          <rect x="376" y="370" width="30" height="180" rx="12"/>
          <ellipse cx="270" cy="400" rx="136" ry="75"/>
        @elseif ($hairStyle === 'curly_afro')
          <ellipse cx="270" cy="390" rx="160" ry="100"/>
          <circle cx="190" cy="420" r="45"/>
          <circle cx="350" cy="420" r="45"/>
          <circle cx="220" cy="370" r="38"/>
          <circle cx="320" cy="370" r="38"/>
        @elseif ($hairStyle === 'bun_high')
          <ellipse cx="270" cy="355" rx="55" ry="50"/>
          <ellipse cx="270" cy="410" rx="132" ry="65"/>
        @elseif ($hairStyle === 'bun_low')
          <ellipse cx="270" cy="640" rx="50" ry="45"/>
          <ellipse cx="270" cy="390" rx="132" ry="68"/>
          <rect x="248" y="570" width="44" height="75" rx="10"/>
        @elseif ($hairStyle === 'braids')
          <ellipse cx="270" cy="395" rx="132" ry="70"/>
          <rect x="230" y="460" width="18" height="160" rx="8"/>
          <rect x="256" y="460" width="18" height="170" rx="8"/>
          <rect x="282" y="460" width="18" height="160" rx="8"/>
          <rect x="308" y="460" width="18" height="155" rx="8"/>
        @endif
      </g>

      {{-- Eyes --}}
      <g id="pvg-eyes">
        {{-- Left eye --}}
        <g id="pvg-eye-left">
          <ellipse id="pvg-eyeball-left"   cx="225" cy="490" rx="22" ry="16" fill="#fff"/>
          <circle  id="pvg-iris-left"      cx="225" cy="492" r="11"  fill="{{ $irisColor }}"/>
          <circle  id="pvg-pupil-left"     cx="225" cy="492" r="6"   fill="#111"/>
          <ellipse id="pvg-eyelid-left"    cx="225" cy="488" rx="24" ry="8"  fill="{{ $skinTone }}" opacity="0"/>
        </g>
        {{-- Right eye --}}
        <g id="pvg-eye-right">
          <ellipse id="pvg-eyeball-right"  cx="315" cy="490" rx="22" ry="16" fill="#fff"/>
          <circle  id="pvg-iris-right"     cx="315" cy="492" r="11"  fill="{{ $irisColor }}"/>
          <circle  id="pvg-pupil-right"    cx="315" cy="492" r="6"   fill="#111"/>
          <ellipse id="pvg-eyelid-right"   cx="315" cy="488" rx="24" ry="8"  fill="{{ $skinTone }}" opacity="0"/>
        </g>
      </g>

      {{-- Eyebrows --}}
      <g id="pvg-brows" stroke="{{ $hairColor }}" stroke-linecap="round" fill="none">
        @if ($browStyle === 'thin_arched')
          <path id="pvg-brow-left"  d="M 203 468 Q 222 459 243 465" stroke-width="4"/>
          <path id="pvg-brow-right" d="M 297 465 Q 318 459 337 468" stroke-width="4"/>
        @elseif ($browStyle === 'thick_flat')
          <path id="pvg-brow-left"  d="M 203 468 Q 222 464 243 468" stroke-width="8"/>
          <path id="pvg-brow-right" d="M 297 468 Q 318 464 337 468" stroke-width="8"/>
        @elseif ($browStyle === 'bushy')
          <path id="pvg-brow-left"  d="M 200 470 Q 222 460 245 467" stroke-width="10"/>
          <path id="pvg-brow-right" d="M 295 467 Q 318 460 340 470" stroke-width="10"/>
        @else {{-- tapered --}}
          <path id="pvg-brow-left"  d="M 205 470 Q 222 461 242 467" stroke-width="6"/>
          <path id="pvg-brow-right" d="M 298 467 Q 318 461 335 470" stroke-width="6"/>
        @endif
      </g>

      {{-- Nose --}}
      <g id="pvg-nose" fill="none" stroke="{{ $shadowTone }}" stroke-linecap="round">
        <path d="M 270 515 Q 258 540 250 548 Q 262 558 270 556 Q 278 558 290 548 Q 282 540 270 515" stroke-width="2.5"/>
      </g>

      {{-- Mouth --}}
      <g id="pvg-mouth">
        {{-- Lips (upper & lower) --}}
        <path id="pvg-upper-lip"
          d="M 235 588 Q 252 582 270 585 Q 288 582 305 588"
          fill="{{ $lipColor }}" stroke="{{ $lipDark }}" stroke-width="1.5"/>
        <path id="pvg-lower-lip"
          d="M 235 588 Q 270 605 305 588"
          fill="{{ $lipLight }}" stroke="{{ $lipDark }}" stroke-width="1.5"/>
        {{-- Inner mouth (shown when open) --}}
        <path id="pvg-mouth-inner"
          d="M 240 590 Q 270 600 300 590 Q 270 618 240 590Z"
          fill="#3A1010" opacity="0"/>
        {{-- Teeth (shown when wide open) --}}
        <rect id="pvg-teeth" x="248" y="590" width="44" height="14" rx="4" fill="#F8F8F8" opacity="0"/>
      </g>

      {{-- Glasses (optional) --}}
      @if ($glasses !== 'none')
        <g id="pvg-glasses" fill="none" stroke="#333" stroke-width="2.5">
          @if ($glasses === 'round')
            <circle cx="225" cy="490" r="28"/>
            <circle cx="315" cy="490" r="28"/>
            <line x1="253" y1="490" x2="287" y2="490"/>
            <line x1="197" y1="490" x2="175" y2="485"/>
            <line x1="343" y1="490" x2="365" y2="485"/>
          @elseif ($glasses === 'rectangular')
            <rect x="198" y="474" width="56" height="32" rx="5"/>
            <rect x="288" y="474" width="56" height="32" rx="5"/>
            <line x1="254" y1="488" x2="288" y2="488"/>
            <line x1="198" y1="488" x2="175" y2="485"/>
            <line x1="344" y1="488" x2="365" y2="485"/>
          @elseif ($glasses === 'cat_eye')
            <path d="M 198 490 Q 225 466 254 476 L 254 506 Q 225 510 198 490Z"/>
            <path d="M 288 476 Q 317 466 344 490 Q 317 510 288 506Z"/>
            <line x1="254" y1="490" x2="288" y2="490"/>
            <line x1="198" y1="490" x2="175" y2="485"/>
            <line x1="344" y1="490" x2="365" y2="485"/>
          @elseif ($glasses === 'half_rim')
            <path d="M 198 490 Q 225 474 254 490" stroke-width="2.5"/>
            <path d="M 288 490 Q 315 474 344 490" stroke-width="2.5"/>
            <line x1="254" y1="490" x2="288" y2="490"/>
          @endif
        </g>
      @endif
    </g>{{-- /pvg-head --}}

  </svg>{{-- /pvg-rig --}}

  {{-- Broadcast Tool Overlay (M3) --}}
  @if (!empty($broadcastTool) && $broadcastTool !== 'none')
    <div id="pvg-tool" class="pvg-tool-{{ $broadcastTool }}">
      <span class="pvg-tag">{{ $toolHeadline ?? 'LIVE' }}</span>
      <span class="pvg-label">{{ $toolSub ?? 'REPORT' }}</span>
    </div>
  @endif

  {{-- AI Disclosure Badge --}}
  <div id="pvg-badge">🤖 AI-generated presenter</div>

</div>{{-- /pvg-presenter --}}

<script>
(function () {
  /* ── mulberry32 PRNG (canonical, identical to PHP AvatarPrng — same seed → same output) ── */
  function mulberry32(a) {
    return function () {
      var t = (a += 0x6D2B79F5) >>> 0;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  var rng = mulberry32({{ $seed }});

  /* Silence the linter — rng is available for future variation picks */
  void rng;

  /* ── Viseme openness → SVG mouth morph ── */
  var lipSyncTrack = {!! json_encode($lipSyncTrack) !!};
  var FPS = {{ $fps ?? 30 }};

  /* ── Blink scheduler ── */
  var BLINK_INTERVAL_MIN = 60 / 25; /* 25 per min max = every 2.4s */
  var BLINK_INTERVAL_MAX = 60 / 8;  /* 8  per min min = every 7.5s */

  function nextBlinkDelay() {
    return BLINK_INTERVAL_MIN + rng() * (BLINK_INTERVAL_MAX - BLINK_INTERVAL_MIN);
  }

  /* ── Master GSAP timeline ── */
  var tl = gsap.timeline({ paused: true });

  /* 1. AI disclosure badge: visible 0–3s, then fade */
  tl.set('#pvg-badge', { opacity: 1 }, 0);
  tl.to('#pvg-badge', { opacity: 0, duration: 0.4, ease: 'power2.in' }, 3.0);

  /* 2. Blink animations — scheduled across full duration */
  var dur = {{ $audioDuration ?? 10.0 }};
  var blinkTime = nextBlinkDelay();
  while (blinkTime < dur - 0.5) {
    tl.to(['#pvg-eyelid-left', '#pvg-eyelid-right'],
      { opacity: 1, duration: 0.06, ease: 'power1.in'  }, blinkTime);
    tl.to(['#pvg-eyelid-left', '#pvg-eyelid-right'],
      { opacity: 0, duration: 0.09, ease: 'power1.out' }, blinkTime + 0.06);
    blinkTime += nextBlinkDelay();
  }

  /* 3. Lip-sync morphs from track */
  lipSyncTrack.forEach(function (entry) {
    var t      = entry.frame / FPS;
    var open   = entry.openness;

    /* Scale mouth-inner and teeth based on openness */
    tl.set('#pvg-mouth-inner', { opacity: open * 0.85 }, t);
    tl.set('#pvg-teeth',       { opacity: open > 0.7 ? (open - 0.7) * 3 : 0 }, t);

    /* Scale lower lip scaleY to simulate jaw drop */
    tl.set('#pvg-lower-lip', { scaleY: 1 + open * 0.6, transformOrigin: 'top center' }, t);

    /* Upper lip slight lift */
    tl.set('#pvg-upper-lip', { y: -open * 3, transformOrigin: 'bottom center' }, t);
  });

  /* 4. Camera motion on rig (M3 variation) */
  @if (($cameraMotion ?? 'calm_editorial') === 'slow_push_in')
    tl.to('#pvg-rig', { scale: 1.05, transformOrigin: '50% 45%', duration: dur, ease: 'none', overwrite: 'auto' }, 0);
  @elseif (($cameraMotion ?? 'calm_editorial') === 'dynamic_float')
    tl.to('#pvg-rig', { x: 6, y: -6, duration: 2.8, ease: 'sine.inOut', yoyo: true, repeat: Math.max(0, Math.floor(dur / 2.8)), overwrite: 'auto' }, 0);
  @else
    tl.to('#pvg-rig', { y: -5, duration: 3.2, ease: 'sine.inOut', yoyo: true, repeat: Math.max(0, Math.floor(dur / 3.2)), overwrite: 'auto' }, 0);
  @endif

  /* 5. Presenter entrance transition (M3 variation) */
  @if (($transitionStyle ?? 'slide_up') === 'fade')
    tl.fromTo('#pvg-presenter',
      { opacity: 0 },
      { opacity: 1, duration: 0.7, ease: 'power2.out', overwrite: 'auto' },
      0);
  @elseif (($transitionStyle ?? 'slide_up') === 'wipe')
    tl.fromTo('#pvg-presenter',
      { opacity: 0, scale: 0.94 },
      { opacity: 1, scale: 1, duration: 0.55, ease: 'power3.out', overwrite: 'auto' },
      0);
  @else
    tl.fromTo('#pvg-presenter',
      { opacity: 0, y: 35 },
      { opacity: 1, y: 0, duration: 0.55, ease: 'power3.out', overwrite: 'auto' },
      0);
  @endif

  /* 6. Broadcast Tool entrance reveal (M3) */
  var toolEl = document.getElementById('pvg-tool');
  if (toolEl) {
    tl.fromTo('#pvg-tool',
      { opacity: 0, y: 22 },
      { opacity: 1, y: 0, duration: 0.5, ease: 'back.out(1.4)' },
      0.35);
  }

  /* 7. Emotion Expressions & Gestures (M4) */
  var emotionTimeline = {!! json_encode($emotionTimeline ?? []) !!};
  emotionTimeline.forEach(function (beat) {
    var tStart = beat.start;
    var tReturn = beat.gesture_return_time;
    var offsets = beat.offsets || {};

    /* Eyebrow expression morph */
    tl.to('#pvg-brow-left', {
      y: offsets.brow_y || 0,
      rotate: offsets.brow_tilt || 0,
      transformOrigin: 'center center',
      duration: 0.35,
      ease: 'power2.out'
    }, tStart);

    tl.to('#pvg-brow-right', {
      y: offsets.brow_y || 0,
      rotate: -(offsets.brow_tilt || 0),
      transformOrigin: 'center center',
      duration: 0.35,
      ease: 'power2.out'
    }, tStart);

    /* Eye aperture scale */
    if (offsets.eye_scale && offsets.eye_scale !== 1.0) {
      tl.to('#pvg-eyes', {
        scaleY: offsets.eye_scale,
        transformOrigin: 'center center',
        duration: 0.35,
        ease: 'power1.out'
      }, tStart);
    }

    /* Gesture lifecycle with guaranteed return-to-rest */
    if (beat.gesture === 'subtle_nod') {
      tl.to('#pvg-head', { rotate: 3.5, y: 4, duration: 0.45, ease: 'sine.inOut' }, tStart);
      tl.to('#pvg-head', { rotate: 0, y: 0, duration: 0.45, ease: 'sine.out' }, tReturn);
    } else if (beat.gesture === 'thoughtful_head_tilt') {
      tl.to('#pvg-head', { rotate: -3.0, duration: 0.55, ease: 'power2.out' }, tStart);
      tl.to('#pvg-head', { rotate: 0, duration: 0.50, ease: 'power2.inOut' }, tReturn);
    } else if (beat.gesture === 'hand_emphasize') {
      tl.to('#pvg-shoulders', { y: -4, scaleX: 1.02, duration: 0.4, ease: 'back.out(1.2)' }, tStart);
      tl.to('#pvg-shoulders', { y: 0, scaleX: 1.0, duration: 0.4, ease: 'sine.out' }, tReturn);
    } else {
      /* Rest pose guarantee */
      tl.to('#pvg-head', { rotate: 0, y: 0, duration: 0.3, ease: 'power1.out' }, tStart);
      tl.to('#pvg-shoulders', { y: 0, scaleX: 1.0, duration: 0.3, ease: 'power1.out' }, tStart);
    }
  });

  window.__timelines = window.__timelines || {};
  window.__timelines['pvg-presenter'] = tl;
})();
</script>
</template>
