/**
 * gearRenderer — draws the drum-card gears with three.js.
 *
 * ONE shared WebGLRenderer serves every gear on the page. Browsers cap
 * live WebGL contexts (~16 in Chromium; older ones are silently dropped),
 * and a drum grid can exceed that, so each card owns a plain 2D canvas and
 * this module renders each gear offscreen, then blits it across with
 * `drawImage`. The bezel progress arc is drawn on the same 2D canvas.
 *
 * Cost control — a dashboard must not burn battery on decoration:
 *   - one requestAnimationFrame loop for all gears, alive only while a
 *     VISIBLE gear is moving or needs a repaint;
 *   - gears scrolled out of view (IntersectionObserver) are skipped;
 *   - a stationary gear is drawn once, then costs nothing;
 *   - `prefers-reduced-motion` freezes every gear (state is still shown by
 *     colour and arc);
 *   - when the last gear detaches, the renderer and every GPU resource is
 *     disposed.
 *
 * What a gear MEANS lives in `lib/gearModel` (pure, tested). This file
 * only draws what it is told. It is loaded with a dynamic import so
 * three.js stays out of the main bundle.
 */
import {
  ACESFilmicToneMapping,
  DirectionalLight,
  ExtrudeGeometry,
  Group,
  Mesh,
  MeshStandardMaterial,
  OrthographicCamera,
  Path,
  PMREMGenerator,
  Scene,
  Shape,
  WebGLRenderer,
  type BufferGeometry,
  type WebGLRenderTarget,
} from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { gearOutline, type GearMode, type GearVisual } from '@/lib/gearModel';

/** Offscreen render size; each card's canvas scales this to its own size. */
const RENDER_PX = 192;
/** Half-extent of the orthographic view, in scene units (gear tip ≈ 1). */
const VIEW = 1.3;
const PLATE_DEPTH = 0.3;
const HUB_DEPTH = 0.44;
/** Multiplier on a gear's speed while its card is hovered or focused. */
const BOOST = 3;
/** Larger values make speed changes settle faster. */
const EASE = 5;
const TWO_PI = Math.PI * 2;

/**
 * Metal picks up a lot of the studio environment's brightness, so base
 * colours are deliberately darker than the brand tokens they stand for
 * (the maroon rendered coral at its true value). `shine` scales how much
 * of that environment each state reflects: an idle gear is dull so it
 * reads as dormant next to a bright, running one.
 */
const PALETTE = {
  light: {
    running: '#4a0606',
    overdue: '#92400e',
    idle: '#7b828c',
    track: 'rgba(0,0,0,0.10)',
    arc: { running: '#800000', overdue: '#d97706', idle: '#9ca3af' },
  },
  dark: {
    running: '#d4d4d4',
    overdue: '#d9a114',
    idle: '#3f4652',
    track: 'rgba(255,255,255,0.16)',
    arc: { running: '#e8e8e8', overdue: '#fbbf24', idle: '#6b7280' },
  },
} as const;

const SHINE: Record<GearMode, number> = { running: 0.7, overdue: 0.7, idle: 0.4 };

export interface GearHandle {
  update(visual: GearVisual): void;
  setBoost(on: boolean): void;
  dispose(): void;
}

export interface AttachOptions {
  /** Offsets the starting angle so a grid of gears is not in lockstep. */
  seed: number;
  /** Fired after the first frame lands on the canvas. */
  onReady(): void;
  /** Fired if WebGL is unavailable or the context is lost. */
  onFail(): void;
}

interface Instance {
  canvas: HTMLCanvasElement;
  ctx: CanvasRenderingContext2D;
  opts: AttachOptions;
  visual: GearVisual;
  angle: number;
  speed: number;
  boost: boolean;
  visible: boolean;
  dirty: boolean;
  ready: boolean;
}

interface Shared {
  renderer: WebGLRenderer;
  scene: Scene;
  camera: OrthographicCamera;
  gear: Group;
  material: MeshStandardMaterial;
  geometries: BufferGeometry[];
  envTarget: WebGLRenderTarget;
  instances: Set<Instance>;
  byElement: WeakMap<Element, Instance>;
  io: IntersectionObserver;
  themeObserver: MutationObserver;
  motion: MediaQueryList;
  onMotionChange: () => void;
  onContextLost: (e: Event) => void;
  raf: number | null;
  last: number;
}

let shared: Shared | null = null;

function isDark(): boolean {
  return document.documentElement.classList.contains('dark');
}


function circle(radius: number, clockwise: boolean, cx = 0, cy = 0): Path {
  const path = new Path();
  path.absarc(cx, cy, radius, 0, TWO_PI, clockwise);
  return path;
}

/** Toothed plate with a bore and six lightening holes, plus a raised hub. */
function buildGear(material: MeshStandardMaterial): { group: Group; geometries: BufferGeometry[] } {
  const plateShape = new Shape();
  gearOutline().forEach(([x, y], i) => {
    if (i === 0) plateShape.moveTo(x, y);
    else plateShape.lineTo(x, y);
  });
  plateShape.closePath();
  // Holes wind the opposite way to the outer contour.
  plateShape.holes.push(circle(0.2, true));
  for (let i = 0; i < 6; i += 1) {
    const a = (i / 6) * TWO_PI + Math.PI / 6;
    plateShape.holes.push(circle(0.12, true, Math.cos(a) * 0.56, Math.sin(a) * 0.56));
  }
  const plate = new ExtrudeGeometry(plateShape, {
    depth: PLATE_DEPTH,
    bevelEnabled: true,
    bevelThickness: 0.025,
    bevelSize: 0.02,
    bevelSegments: 2,
    curveSegments: 20,
  });
  plate.translate(0, 0, -PLATE_DEPTH / 2);

  const hubShape = new Shape();
  hubShape.absarc(0, 0, 0.36, 0, TWO_PI, false);
  hubShape.holes.push(circle(0.2, true));
  const hub = new ExtrudeGeometry(hubShape, {
    depth: HUB_DEPTH,
    bevelEnabled: true,
    bevelThickness: 0.02,
    bevelSize: 0.015,
    bevelSegments: 2,
    curveSegments: 28,
  });
  hub.translate(0, 0, -HUB_DEPTH / 2);

  const group = new Group();
  group.add(new Mesh(plate, material), new Mesh(hub, material));
  return { group, geometries: [plate, hub] };
}

function createShared(): Shared | null {
  let renderer: WebGLRenderer;
  try {
    renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' });
  } catch {
    return null;
  }
  renderer.setPixelRatio(1);
  renderer.setSize(RENDER_PX, RENDER_PX, false);
  renderer.setClearColor(0x000000, 0);
  renderer.toneMapping = ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;

  const scene = new Scene();
  // Generating the environment map compiles three's own PMREM blur
  // shader, which Direct3D (ANGLE on Windows) answers with a harmless
  // float-precision warning (X4122) that three then logs to the console
  // on every page load. Silence the check for that one step only, so a
  // genuine compile error in the gear's own material still surfaces.
  renderer.debug.checkShaderErrors = false;
  let envTarget: WebGLRenderTarget;
  try {
    const pmrem = new PMREMGenerator(renderer);
    const envScene = new RoomEnvironment();
    envTarget = pmrem.fromScene(envScene, 0.04);
    envScene.dispose();
    pmrem.dispose();
  } finally {
    renderer.debug.checkShaderErrors = true;
  }
  scene.environment = envTarget.texture;

  const key = new DirectionalLight(0xffffff, 1.1);
  key.position.set(-2, 3, 5);
  scene.add(key);

  const material = new MeshStandardMaterial({ metalness: 0.8, roughness: 0.38 });
  const { group, geometries } = buildGear(material);
  // A slight tilt shows the gear's thickness and bevel; the gear itself
  // spins about its own Z inside this pivot.
  const pivot = new Group();
  pivot.rotation.set(-0.3, 0.12, 0);
  pivot.add(group);
  scene.add(pivot);

  const camera = new OrthographicCamera(-VIEW, VIEW, VIEW, -VIEW, 0.1, 20);
  camera.position.set(0, 0, 6);

  const instances = new Set<Instance>();
  const byElement = new WeakMap<Element, Instance>();

  const io = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        const inst = byElement.get(entry.target);
        if (inst === undefined) continue;
        inst.visible = entry.isIntersecting;
        if (inst.visible) markDirty(inst);
      }
    },
    { rootMargin: '100px' },
  );

  // Theme switches change the palette, but a stationary gear has no loop
  // running to notice, so repaint explicitly.
  const themeObserver = new MutationObserver(() => {
    instances.forEach(markDirty);
  });
  themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const onMotionChange = () => instances.forEach(markDirty);
  motion.addEventListener('change', onMotionChange);

  const onContextLost = (e: Event) => {
    e.preventDefault();
    failAll();
  };
  renderer.domElement.addEventListener('webglcontextlost', onContextLost);

  return {
    renderer,
    scene,
    camera,
    gear: group,
    material,
    geometries,
    envTarget,
    instances,
    byElement,
    io,
    themeObserver,
    motion,
    onMotionChange,
    onContextLost,
    raf: null,
    last: 0,
  };
}

function teardown(): void {
  const s = shared;
  if (s === null) return;
  shared = null;
  if (s.raf !== null) cancelAnimationFrame(s.raf);
  s.io.disconnect();
  s.themeObserver.disconnect();
  s.motion.removeEventListener('change', s.onMotionChange);
  s.renderer.domElement.removeEventListener('webglcontextlost', s.onContextLost);
  s.geometries.forEach((g) => g.dispose());
  s.material.dispose();
  s.envTarget.dispose();
  s.renderer.dispose();
  s.renderer.forceContextLoss();
}

function failAll(): void {
  const s = shared;
  if (s === null) return;
  const failed = [...s.instances];
  teardown();
  failed.forEach((inst) => inst.opts.onFail());
}

function drawBezel(
  ctx: CanvasRenderingContext2D,
  w: number,
  h: number,
  progress: number,
  colour: string,
  track: string,
): void {
  const cx = w / 2;
  const cy = h / 2;
  const radius = (w / 2) * 0.9;
  ctx.lineCap = 'round';
  ctx.lineWidth = w * 0.05;
  ctx.strokeStyle = track;
  ctx.beginPath();
  ctx.arc(cx, cy, radius, 0, TWO_PI);
  ctx.stroke();
  if (progress > 0) {
    ctx.strokeStyle = colour;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, -Math.PI / 2, -Math.PI / 2 + progress * TWO_PI);
    ctx.stroke();
  }
}

function draw(s: Shared, inst: Instance): void {
  const palette = PALETTE[isDark() ? 'dark' : 'light'];
  const mode = inst.visual.mode;
  s.gear.rotation.z = inst.angle;
  s.material.color.set(palette[mode]);
  s.material.envMapIntensity = SHINE[mode];
  s.renderer.render(s.scene, s.camera);

  const { ctx, canvas } = inst;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(s.renderer.domElement, 0, 0, RENDER_PX, RENDER_PX, 0, 0, canvas.width, canvas.height);
  drawBezel(ctx, canvas.width, canvas.height, inst.visual.progress, palette.arc[mode], palette.track);

  if (!inst.ready) {
    inst.ready = true;
    inst.opts.onReady();
  }
}

function schedule(): void {
  const s = shared;
  if (s === null || s.raf !== null) return;
  s.last = performance.now();
  s.raf = requestAnimationFrame(tick);
}

function tick(now: number): void {
  const s = shared;
  if (s === null) return;
  s.raf = null;
  const dt = Math.min(0.05, Math.max(0, (now - s.last) / 1000));
  s.last = now;
  const reduceMotion = s.motion.matches;

  let again = false;
  for (const inst of s.instances) {
    if (!inst.visible) continue;
    const base = reduceMotion ? 0 : inst.visual.speed;
    const target = inst.boost && base > 0 ? base * BOOST : base;
    const diff = target - inst.speed;
    inst.speed = Math.abs(diff) < 0.001 ? target : inst.speed + diff * (1 - Math.exp(-dt * EASE));

    const moving = Math.abs(inst.speed) > 0.0005;
    if (moving) inst.angle += inst.speed * dt;
    if (moving || inst.dirty) {
      inst.dirty = false;
      draw(s, inst);
    }
    if (moving || Math.abs(target - inst.speed) > 0.001) again = true;
  }

  if (again) {
    s.raf = requestAnimationFrame(tick);
  }
}

function markDirty(inst: Instance): void {
  inst.dirty = true;
  schedule();
}

/**
 * Bind a gear to a card's 2D canvas. The caller sets the canvas's
 * width/height (device pixels) first. Returns null when WebGL is
 * unavailable, in which case the caller keeps its static fallback.
 */
export function attachGear(
  canvas: HTMLCanvasElement,
  initial: GearVisual,
  opts: AttachOptions,
): GearHandle | null {
  const ctx = canvas.getContext('2d');
  if (ctx === null) return null;
  shared ??= createShared();
  const s = shared;
  if (s === null) return null;

  const inst: Instance = {
    canvas,
    ctx,
    opts,
    visual: initial,
    angle: opts.seed * 0.9,
    speed: 0,
    boost: false,
    visible: false,
    dirty: true,
    ready: false,
  };
  s.instances.add(inst);
  s.byElement.set(canvas, inst);
  s.io.observe(canvas);

  return {
    update(visual) {
      inst.visual = visual;
      markDirty(inst);
    },
    setBoost(on) {
      inst.boost = on;
      schedule();
    },
    dispose() {
      const cur = shared;
      if (cur === null) return;
      cur.io.unobserve(canvas);
      cur.byElement.delete(canvas);
      cur.instances.delete(inst);
      if (cur.instances.size === 0) teardown();
    },
  };
}
