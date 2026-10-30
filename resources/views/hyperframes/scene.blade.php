<template>
  <style>
    #root { position: absolute; inset: 0; }
    #{{ $sceneId }}-content {
      position: absolute;
      top: {{ $safe['top'] }}px;
      right: {{ $safe['side'] }}px;
      bottom: {{ $safe['bottom'] }}px;
      left: {{ $safe['side'] }}px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      text-align: {{ $isRtl ? 'right' : 'left' }};
      font-family: {{ $fontFamily }};
      color: var(--ink);
    }
    #{{ $sceneId }}-meta {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: {{ (int) round($safe['kicker'] * 0.6) }}px;
    }
    #{{ $sceneId }}-kicker {
      font-size: {{ $safe['kicker'] }}px;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--accent);
    }
    #{{ $sceneId }}-counter {
      font-size: {{ (int) round($safe['kicker'] * 0.8) }}px;
      font-weight: 700;
      letter-spacing: 0.10em;
      color: rgba(255, 255, 255, 0.30);
    }
    #{{ $sceneId }}-title {
      font-size: {{ $titleSize }}px;
      font-weight: 900;
      line-height: 1.04;
      letter-spacing: -0.035em;
      text-shadow: 0 12px 44px rgba(var(--accent-rgb), 0.26), 0 3px 14px rgba(0, 0, 0, 0.55);
      max-width: {{ $safe['text_width'] }}px;
    }
    #{{ $sceneId }}-rule {
      width: {{ $safe['rule_width'] }}px;
      height: {{ $safe['rule_height'] }}px;
      border-radius: {{ $safe['rule_height'] }}px;
      margin: {{ (int) round($safe['gap'] * 0.7) }}px 0;
      background: linear-gradient(90deg, var(--accent), var(--glow));
      box-shadow: 0 0 26px rgba(var(--accent-rgb), 0.55);
    }
    #{{ $sceneId }}-desc {
      font-size: {{ $safe['body'] }}px;
      line-height: 1.38;
      font-weight: 500;
      color: var(--dim);
      max-width: {{ $safe['text_width'] }}px;
    }
    #{{ $sceneId }}-highlight {
      margin-top: {{ $safe['gap'] }}px;
      padding: {{ (int) round($safe['gap'] * 0.6) }}px {{ (int) round($safe['gap'] * 0.8) }}px;
      border-radius: {{ $safe['radius'] }}px;
      background: rgba(var(--accent-rgb), 0.10);
      border-left: {{ max(3, (int) round($safe['rule_height'] * 0.7)) }}px solid var(--accent);
      font-size: {{ $safe['highlight'] }}px;
      line-height: 1.32;
      font-weight: 600;
      color: rgba(255, 255, 255, 0.94);
      max-width: {{ $safe['text_width'] }}px;
    }
    #{{ $sceneId }}-media {
      margin-top: {{ $safe['gap'] }}px;
      width: 100%;
      max-width: {{ $safe['text_width'] }}px;
      border-radius: {{ $safe['radius'] }}px;
      overflow: hidden;
      border: 1px solid var(--border);
      box-shadow: 0 18px 52px rgba(0, 0, 0, 0.45);
    }
    #{{ $sceneId }}-media img {
      display: block;
      width: 100%;
      height: {{ $safe['media_height'] }}px;
      object-fit: cover;
    }
    #{{ $sceneId }}-credit {
      font-size: {{ $safe['credit'] }}px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.45);
      margin-top: 12px;
    }
  </style>

  <div id="root" data-composition-id="{{ $sceneId }}" data-width="{{ $width }}" data-height="{{ $height }}" data-sfx-cue="{{ $sfxCue }}">
    <div id="{{ $sceneId }}-content">
      <div id="{{ $sceneId }}-meta">
        <div id="{{ $sceneId }}-kicker">{{ $kicker }}</div>
        <div id="{{ $sceneId }}-counter">{{ $indexLabel }}</div>
      </div>
      <div id="{{ $sceneId }}-title">{{ $title }}</div>
      <div id="{{ $sceneId }}-rule"></div>
      <div id="{{ $sceneId }}-desc">{{ $description }}</div>

      @if ($highlight !== '')
        <div id="{{ $sceneId }}-highlight">{{ $highlight }}</div>
      @endif

      @if ($media !== null)
        <div id="{{ $sceneId }}-media">
          <img id="{{ $sceneId }}-media-img" src="{{ $media['src'] }}" alt="{{ $media['alt'] }}" />
        </div>
        <div id="{{ $sceneId }}-credit">{{ $media['credit'] }}</div>
      @endif
    </div>
  </div>

  <script>
    const tl = gsap.timeline({ paused: true });
    tl.fromTo("#{{ $sceneId }}-kicker", { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: {{ $motion['kicker'] }}, ease: "power3.out" }, 0.06);
    tl.fromTo("#{{ $sceneId }}-title", { opacity: 0, y: 64, scale: 0.94 }, { opacity: 1, y: 0, scale: 1, duration: {{ $motion['title'] }}, ease: "power4.out" }, 0.16);
    tl.fromTo("#{{ $sceneId }}-rule", { scaleX: 0, opacity: 0 }, { scaleX: 1, opacity: 1, duration: {{ $motion['rule'] }}, ease: "power3.inOut" }, {{ $motion['rule_start'] }});
    tl.fromTo("#{{ $sceneId }}-desc", { opacity: 0, y: 32 }, { opacity: 1, y: 0, duration: {{ $motion['body'] }}, ease: "power2.out" }, {{ $motion['body_start'] }});
    @if ($highlight !== '')
    tl.fromTo("#{{ $sceneId }}-highlight", { opacity: 0, y: 26 }, { opacity: 1, y: 0, duration: {{ $motion['body'] }}, ease: "power2.out" }, {{ $motion['highlight_start'] }});
    @endif
    @if ($media !== null)
    tl.fromTo("#{{ $sceneId }}-media", { opacity: 0, y: 40, scale: 0.97 }, { opacity: 1, y: 0, scale: 1, duration: {{ $motion['media'] }}, ease: "power3.out" }, {{ $motion['media_start'] }});
    @endif
    tl.fromTo("#{{ $sceneId }}-content", { y: 0 }, { y: -14, duration: {{ $motion['drift'] }}, ease: "none" }, 0);
    tl.to("#{{ $sceneId }}-content", { opacity: 0, y: -30, duration: {{ $motion['exit'] }}, ease: "power2.in" }, {{ $motion['exit_start'] }});
    tl.set("#{{ $sceneId }}-content", { opacity: 0, visibility: "hidden" }, {{ $motion['end'] }});
    window.__timelines["{{ $sceneId }}"] = tl;
  </script>
</template>
