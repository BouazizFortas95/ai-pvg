import { useCurrentFrame, interpolate, AbsoluteFill, Sequence, Audio } from 'remotion';

export const VideoScript: React.FC<{
  scenes: Array<{
    order: number;
    type: string;
    duration: number;
    headline: string;
    description: string;
    narration: string;
    visual_keywords: string[];
    claim?: string;
    source_news_ids?: number[];
    directives?: {
      camera_motion?: string;
      b_roll_query?: string;
      highlight_text?: string;
      sfx_cue?: string;
      emotion_tone?: string;
      duration_seconds?: number;
    };
  }>;
  title: string;
  language: string;
  targetDuration: number;
  template?: string;
  audioUrl?: string;
  captionTrack?: {
    language: string;
    wordTimings?: Array<{ word: string; startMs: number; endMs: number; source: 'estimated' | 'verified' }>;
    chunks?: Array<{ text: string; rtl?: boolean; language: string }>;
    source?: 'estimated' | 'verified';
    status?: string;
  };
  visualAssets?: Array<{ path: string; attribution?: string; status: string }> | null;
}> = ({ scenes, title, language, targetDuration, template = 'cyber', audioUrl, captionTrack, visualAssets }) => {
  const frame = useCurrentFrame();
  const durationFrames = targetDuration * 30;
  const isRTL = language === 'ar';
  const bg = template === 'breaking' ? '#0a0e27' : template === 'minimal' ? '#f8fafc' : '#0f172a';
  const accent = '#00d4ff';

  return (
    <AbsoluteFill style={{ background: bg, color: template === 'minimal' ? '#0f172a' : '#fff', fontFamily: 'system-ui, sans-serif', direction: isRTL ? 'rtl' : 'ltr', overflow: 'hidden' }}>
      <AbsoluteFill style={{ padding: 60, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', textAlign: 'center' }}>
        <h1 style={{ fontSize: 48, fontWeight: 900, color: accent, letterSpacing: '-0.04em', marginBottom: 24, lineHeight: 1.1 }}>{title}</h1>
        {audioUrl && <Audio src={audioUrl} volume={0.9} startFrom={0} />}
        {visualAssets === null ? (
          <span style={{ fontSize: 10, opacity: 0.5 }}>Assets unavailable — truthful fallback</span>
        ) : visualAssets && visualAssets.length > 0 ? (
          <div style={{ marginTop: 20, display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {visualAssets.map((v, i) => (
              <img key={i} src={v.path} alt={v.attribution || 'asset'} style={{ maxHeight: 120, borderRadius: 8, opacity: v.status === 'verified' ? 1 : 0.4 }} />
            ))}
          </div>
        ) : null}
        {captionTrack && captionTrack.source && (
          <div style={{ position: 'absolute', bottom: 80, left: 40, right: 40, direction: captionTrack.language === 'ar' ? 'rtl' : 'ltr', color: '#fff', fontSize: 22, fontWeight: 600, textAlign: 'center', background: 'rgba(0,0,0,0.55)', borderRadius: 8, padding: '12px 16px', pointerEvents: 'none' }}>
            <span style={{ opacity: 0.9, fontSize: 12, display: 'block', marginBottom: 4 }}>{captionTrack.source === 'verified' ? 'Verified' : 'Estimated'} • {captionTrack.language.toUpperCase()}</span>
            {captionTrack.chunks && captionTrack.chunks[0]?.text}
          </div>
        )}
        <p style={{ fontSize: 20, opacity: 0.8 }}>{language.toUpperCase()} • {targetDuration}s</p>
        <div style={{ marginTop: 40, display: 'flex', gap: 20, flexWrap: 'wrap', justifyContent: 'center' }}>
          {scenes.map((s) => (
            <div key={s.order} style={{ background: 'rgba(255,255,255,0.08)', borderRadius: 12, padding: 16, minWidth: 140, textAlign: 'left' }}>
              <div style={{ fontWeight: 700, color: accent, fontSize: 14 }}>{s.headline}</div>
              <div style={{ fontSize: 12, opacity: 0.7, marginTop: 4 }}>{s.duration}s • {s.type}</div>
              <div style={{ fontSize: 11, opacity: 0.6, marginTop: 6 }}>{s.description}</div>
            </div>
          ))}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

export const Root: React.FC = () => <VideoScript scenes={[]} title="AI-PVG" language="en" targetDuration={45} />;
