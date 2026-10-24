import { Composition } from 'remotion';
import { VideoScript } from './VideoScript';

export const Root: React.FC = () => (
  <Composition
    id="VideoScript"
    component={VideoScript}
    durationInFrames={450}
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
    calculateMetadata={({ props }) => ({
      durationInFrames: Math.max(1, Math.round((props.targetDuration || 15) * 30)),
      width: 1080,
      height: 1920,
      fps: 30,
    })}
  />
);
