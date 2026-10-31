import { jsx as _jsx } from "react/jsx-runtime";
import { Composition } from 'remotion';
import { VideoScript } from './VideoScript';
export const Root = () => (_jsx(Composition, { id: "VideoScript", component: VideoScript, fps: 30, width: 1080, height: 1920, defaultProps: {
        scenes: [],
        title: 'AI-PVG',
        language: 'en',
        targetDuration: 15,
        template: 'cyber',
    }, calculateMetadata: ({ props }) => {
        const scenes = props.scenes || [];
        const timelineDuration = props.audioTimeline?.total_duration || props.targetDuration;
        const totalSec = timelineDuration && timelineDuration > 0
            ? Number(timelineDuration)
            : scenes.reduce((acc, s) => acc + (s.duration || 5), 0);
        return {
            durationInFrames: Math.max(1, Math.round(totalSec * 30)),
            width: 1080,
            height: 1920,
            fps: 30,
        };
    } }));
