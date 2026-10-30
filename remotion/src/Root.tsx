import { Composition } from 'remotion';
import { VideoScript } from './VideoScript';

export const Root: React.FC = () => (
  <Composition
    id="VideoScript"
    component={VideoScript}
    fps={30}
    width={1080}
    height={1920}
    defaultProps={{
      scenes: [],
      title: 'AI-PVG',
      language: 'en',
      targetDuration: 15,
      template: 'cyber',
    }}
    calculateMetadata={({ props }) => {
      const scenes = props.scenes || [];
      const totalSec = scenes.reduce((acc, s) => acc + (s.duration || 5), 0);
      return {
        durationInFrames: Math.max(1, Math.round(totalSec * 30)),
        width: 1080,
        height: 1920,
        fps: 30,
      };
    }}
  />
);
