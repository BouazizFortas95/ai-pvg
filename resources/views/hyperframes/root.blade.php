<!doctype html>
<html lang="{{ $language }}">
  <head>
    <meta charset="UTF-8" />
    <style>
      :root {
        --ground: {{ $theme['bg'] }};
        --ink: {{ $theme['ink'] }};
        --dim: {{ $theme['textDim'] }};
        --accent: {{ $theme['accent'] }};
        --glow: {{ $theme['glow'] }};
        --accent-rgb: {{ $accentRgb }};
        --panel: {{ $theme['panel'] }};
        --border: rgba(255, 255, 255, 0.16);
      }
      * { margin: 0; padding: 0; box-sizing: border-box; }
      html, body {
        width: {{ $width }}px;
        height: {{ $height }}px;
        overflow: hidden;
        background: var(--ground);
      }
      #root { position: absolute; inset: 0; }
      #hf-backdrop {
        position: absolute;
        inset: 0;
        background:
          radial-gradient(circle at 50% 24%, rgba(var(--accent-rgb), 0.20) 0%, rgba(var(--accent-rgb), 0.05) 46%, rgba(0, 0, 0, 0.88) 100%),
          linear-gradient(180deg, rgba(12, 16, 32, 0.96) 0%, rgba(5, 7, 12, 0.98) 100%);
      }
      #hf-grid {
        position: absolute;
        inset: 0;
        opacity: 0.10;
        background-image:
          linear-gradient(rgba(255, 255, 255, 0.10) 1px, transparent 1px),
          linear-gradient(90deg, rgba(255, 255, 255, 0.10) 1px, transparent 1px);
        background-size: {{ (int) round($width / 9) }}px {{ (int) round($width / 9) }}px;
      }
      #hf-footer {
        position: absolute;
        left: {{ $safe['side'] }}px;
        right: {{ $safe['side'] }}px;
        bottom: {{ $safe['bottom'] + 24 }}px;
        display: flex;
        align-items: center;
        gap: 16px;
        color: var(--dim);
        font-family: {{ $fontFamily }};
        font-size: {{ $safe['footer'] }}px;
        font-weight: 600;
        letter-spacing: 0.10em;
        text-transform: uppercase;
      }
      #hf-footer-rule {
        width: 4px;
        height: {{ $safe['footer'] + 14 }}px;
        border-radius: 4px;
        background: linear-gradient(180deg, var(--accent), rgba(var(--accent-rgb), 0.15));
        box-shadow: 0 0 18px rgba(var(--accent-rgb), 0.55);
      }
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="{{ $compositionId }}" data-start="0" data-duration="{{ $duration }}" data-width="{{ $width }}" data-height="{{ $height }}" data-fps="{{ $fps }}">
      <div id="hf-backdrop"></div>
      <div id="hf-grid"></div>

      @foreach ($sceneHosts as $host)
        <div
          id="{{ $host['id'] }}"
          data-composition-id="{{ $host['id'] }}"
          data-composition-src="compositions/{{ $host['id'] }}.html"
          data-start="{{ $host['start'] }}"
          data-duration="{{ $host['duration'] }}"
          data-track-index="{{ $host['track'] }}"
          data-track-kind="graphics"
          data-width="{{ $width }}"
          data-height="{{ $height }}"
        ></div>
      @endforeach

      @if ($captionHost !== null)
        <div
          id="{{ $captionHost['id'] }}"
          data-composition-id="{{ $captionHost['id'] }}"
          data-composition-src="compositions/{{ $captionHost['id'] }}.html"
          data-start="{{ $captionHost['start'] }}"
          data-duration="{{ $captionHost['duration'] }}"
          data-track-index="{{ $captionHost['track'] }}"
          data-track-kind="captions"
          data-width="{{ $width }}"
          data-height="{{ $height }}"
        ></div>
      @endif

      @foreach ($audioClips as $audio)
        <audio
          id="{{ $audio['id'] }}"
          class="clip"
          src="{{ $audio['src'] }}"
          data-start="{{ $audio['start'] }}"
          data-duration="{{ $audio['duration'] }}"
          data-track-index="{{ $audio['track'] }}"
          data-volume="{{ $audio['volume'] }}"
          data-fade-in="{{ $audio['fade_in'] }}"
          data-fade-out="{{ $audio['fade_out'] }}"
        ></audio>
      @endforeach

      <div id="hf-footer">
        <div id="hf-footer-rule"></div>
        <div id="hf-footer-text">{{ $title }}</div>
      </div>
    </div>

    <script src="assets/vendor/gsap.min.js"></script>
    <script>
      window.__timelines["{{ $compositionId }}"] = gsap.timeline({ paused: true });
    </script>
  </body>
</html>
