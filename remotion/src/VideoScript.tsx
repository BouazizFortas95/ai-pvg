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
  }>;
  title: string;
  language: string;
  targetDuration: number;
  template?: string;
  audioUrl?: string;
}> = ({ scenes, title, language, targetDuration, template = 'cyber', audioUrl }) => {
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
