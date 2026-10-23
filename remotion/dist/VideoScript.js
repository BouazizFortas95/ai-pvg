import { jsx as _jsx, jsxs as _jsxs } from "react/jsx-runtime";
import { useCurrentFrame, AbsoluteFill, Audio } from 'remotion';
export const VideoScript = ({ scenes, title, language, targetDuration, template = 'cyber', audioUrl }) => {
    const frame = useCurrentFrame();
    const durationFrames = targetDuration * 30;
    const isRTL = language === 'ar';
    const bg = template === 'breaking' ? '#0a0e27' : template === 'minimal' ? '#f8fafc' : '#0f172a';
    const accent = '#00d4ff';
    return (_jsx(AbsoluteFill, { style: { background: bg, color: template === 'minimal' ? '#0f172a' : '#fff', fontFamily: 'system-ui, sans-serif', direction: isRTL ? 'rtl' : 'ltr', overflow: 'hidden' }, children: _jsxs(AbsoluteFill, { style: { padding: 60, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', textAlign: 'center' }, children: [_jsx("h1", { style: { fontSize: 48, fontWeight: 900, color: accent, letterSpacing: '-0.04em', marginBottom: 24, lineHeight: 1.1 }, children: title }), audioUrl && _jsx(Audio, { src: audioUrl, volume: 0.9, startFrom: 0 }), _jsxs("p", { style: { fontSize: 20, opacity: 0.8 }, children: [language.toUpperCase(), " \u2022 ", targetDuration, "s"] }), _jsx("div", { style: { marginTop: 40, display: 'flex', gap: 20, flexWrap: 'wrap', justifyContent: 'center' }, children: scenes.map((s) => (_jsxs("div", { style: { background: 'rgba(255,255,255,0.08)', borderRadius: 12, padding: 16, minWidth: 140, textAlign: 'left' }, children: [_jsx("div", { style: { fontWeight: 700, color: accent, fontSize: 14 }, children: s.headline }), _jsxs("div", { style: { fontSize: 12, opacity: 0.7, marginTop: 4 }, children: [s.duration, "s \u2022 ", s.type] }), _jsx("div", { style: { fontSize: 11, opacity: 0.6, marginTop: 6 }, children: s.description })] }, s.order))) })] }) }));
};
export const Root = () => _jsx(VideoScript, { scenes: [], title: "AI-PVG", language: "en", targetDuration: 45 });
