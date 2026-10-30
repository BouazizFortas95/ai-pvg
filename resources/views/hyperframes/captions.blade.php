<template>
  <style>
    #root { position: absolute; inset: 0; }
    .caption-group {
      position: absolute;
      left: {{ $safe['side'] }}px;
      right: {{ $safe['side'] }}px;
      bottom: {{ $safe['caption_bottom'] }}px;
      display: flex;
      justify-content: center;
      pointer-events: none;
    }
    .caption-card {
      max-width: {{ $safe['caption_width'] }}px;
      padding: {{ $safe['caption_padding'] }}px {{ (int) round($safe['caption_padding'] * 1.5) }}px;
      border-radius: {{ $safe['radius'] }}px;
      background: rgba(0, 0, 0, 0.62);
      border: 1px solid rgba(255, 255, 255, 0.10);
      font-family: {{ $fontFamily }};
      font-size: {{ $safe['caption'] }}px;
      line-height: 1.34;
      font-weight: 700;
      color: #ffffff;
      text-align: center;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.6);
    }
    .caption-source {
      display: block;
      margin-bottom: 6px;
      font-size: {{ $safe['credit'] }}px;
      font-weight: 700;
      letter-spacing: 0.10em;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.48);
    }
    .caption-word { display: inline; }
  </style>

  <div id="root" data-composition-id="{{ $captionId }}" data-width="{{ $width }}" data-height="{{ $height }}">
    @foreach ($groups as $group)
      <div id="{{ $group['id'] }}" class="clip" data-start="{{ $group['start'] }}" data-duration="{{ $group['duration'] }}" data-track-index="{{ $group['track'] }}">
        <div id="{{ $group['id'] }}-card" class="caption-group">
          <div class="caption-card">
            <span class="caption-source">{{ $sourceLabel }}</span>
            @foreach ($group['words'] as $word)
              <span id="{{ $word['id'] }}" class="caption-word">{{ $word['text'] }}</span>@if (! $loop->last){{ ' ' }}@endif
            @endforeach
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <script>
    const tl = gsap.timeline({ paused: true });
    @foreach ($groups as $group)
    tl.fromTo("#{{ $group['id'] }}-card", { opacity: 0, y: 22 }, { opacity: 1, y: 0, duration: {{ $motion['enter'] }}, ease: "power2.out" }, {{ $group['start'] }});
    tl.to("#{{ $group['id'] }}-card", { opacity: 0, duration: {{ $motion['exit'] }}, ease: "power1.in" }, {{ $group['exit_start'] }});
    tl.set("#{{ $group['id'] }}-card", { opacity: 0, visibility: "hidden" }, {{ $group['end'] }});
    @foreach ($group['words'] as $word)
    tl.to("#{{ $word['id'] }}", { color: "{{ $theme['accent'] }}", duration: 0.001 }, {{ $word['start'] }});
    tl.to("#{{ $word['id'] }}", { color: "#ffffff", duration: 0.001 }, {{ $word['end'] }});
    @endforeach
    @endforeach
    window.__timelines["{{ $captionId }}"] = tl;
  </script>
</template>
