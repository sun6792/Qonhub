/**
 * Qonhub AI Floating Lines — 浮动光线背景层
 * 适配自 React Bits FloatingLines，使用 Three.js CDN
 */
(function () {
  const VERT = `void main(){gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}`;

  const FRAG = `precision highp float;
uniform float iTime,animationSpeed,bendRadius,bendStrength,bendInfluence,parallaxStrength;
uniform vec3 iResolution;
uniform bool enableTop,enableMiddle,enableBottom,interactive,parallax;
uniform int topLineCount,middleLineCount,bottomLineCount;
uniform float topLineDistance,middleLineDistance,bottomLineDistance;
uniform vec3 topWavePosition,middleWavePosition,bottomWavePosition;
uniform vec2 iMouse,parallaxOffset;
uniform vec3 lineGradient[4];
uniform int lineGradientCount;

mat2 rotate(float r){return mat2(cos(r),sin(r),-sin(r),cos(r));}

vec3 getLineColor(float t){
  if(lineGradientCount<=0) return vec3(0.36,0.4,0.98);
  if(lineGradientCount==1) return lineGradient[0];
  float ct=clamp(t,0.,.9999)*float(lineGradientCount-1);
  int idx=int(floor(ct));
  return mix(lineGradient[idx],lineGradient[min(idx+1,lineGradientCount-1)],fract(ct));
}

float wave(vec2 uv,float offset,vec2 screenUv,vec2 mouseUv,bool shouldBend){
  float t=iTime*animationSpeed;
  float amp=sin(offset+t*.2)*.3;
  float y=sin(uv.x+offset+t*.1)*amp;
  if(shouldBend){
    vec2 d=screenUv-mouseUv;
    float influence=exp(-dot(d,d)*bendRadius);
    y+=(mouseUv.y-screenUv.y)*influence*bendStrength*bendInfluence;
  }
  return .015/max(abs(uv.y-y)+.01,.001)+.008;
}

void main(){
  vec2 baseUv=(2.*gl_FragCoord.xy-iResolution.xy)/iResolution.y;
  baseUv.y*=-1.;
  if(parallax) baseUv+=parallaxOffset;
  vec3 col=vec3(0.);
  vec2 mouseUv=interactive?vec2((2.*iMouse-iResolution.xy)/iResolution.y)*vec2(1.,-1.):vec2(0.);

  if(enableBottom){
    for(int i=0;i<20;i++){
      if(i>=bottomLineCount) break;
      float fi=float(i),t=fi/max(float(bottomLineCount-1),1.);
      float a=bottomWavePosition.z*log(length(baseUv)+1.);
      col+=getLineColor(t)*wave(baseUv*rotate(a)+vec2(bottomLineDistance*fi+bottomWavePosition.x,bottomWavePosition.y),1.5+.2*fi,baseUv,mouseUv,interactive)*.12;
    }
  }
  if(enableMiddle){
    for(int i=0;i<20;i++){
      if(i>=middleLineCount) break;
      float fi=float(i),t=fi/max(float(middleLineCount-1),1.);
      float a=middleWavePosition.z*log(length(baseUv)+1.);
      col+=getLineColor(t)*wave(baseUv*rotate(a)+vec2(middleLineDistance*fi+middleWavePosition.x,middleWavePosition.y),2.+.15*fi,baseUv,mouseUv,interactive)*.25;
    }
  }
  if(enableTop){
    for(int i=0;i<20;i++){
      if(i>=topLineCount) break;
      float fi=float(i),t=fi/max(float(topLineCount-1),1.);
      float a=topWavePosition.z*log(length(baseUv)+1.);
      vec2 ruv=baseUv*rotate(a); ruv.x*=-1.;
      col+=getLineColor(t)*wave(ruv+vec2(topLineDistance*fi+topWavePosition.x,topWavePosition.y),1.+.2*fi,baseUv,mouseUv,interactive)*.06;
    }
  }
  gl_FragColor=vec4(col,1.);
}`;

  class FloatingLines {
    constructor(container, opts = {}) {
      this.container = container;
      this.opts = Object.assign({
        lineCount: [8, 12, 16], lineDistance: [0.06, 0.04, 0.03],
        enabledWaves: ['top', 'middle', 'bottom'],
        animationSpeed: 0.6, interactive: true,
        bendRadius: 6.0, bendStrength: -0.35, mouseDamping: 0.06,
        parallax: true, parallaxStrength: 0.15,
        linesGradient: ['#818cf8', '#a78bfa', '#c4b5fd'],
        mixBlendMode: 'screen'
      }, opts);

      this.targetMouse = { x: -1000, y: -1000, influence: 0 };
      this.currentMouse = { x: -1000, y: -1000, influence: 0 };
      this.targetParallax = { x: 0, y: 0 };
      this.currentParallax = { x: 0, y: 0 };
      this.animId = null;

      if (typeof THREE === 'undefined') {
        console.warn('Three.js not loaded, FloatingLines disabled');
        return;
      }
      this.init();
    }

    init() {
      const ctn = this.container;
      ctn.style.position = 'fixed';
      ctn.style.inset = '0';
      ctn.style.zIndex = '0';
      ctn.style.pointerEvents = 'none';
      ctn.style.mixBlendMode = this.opts.mixBlendMode;

      const o = this.opts;
      const getCount = (w) => {
        const i = o.enabledWaves.indexOf(w);
        return typeof o.lineCount === 'number' ? o.lineCount : (o.lineCount[i] ?? 6);
      };
      const getDist = (w) => {
        const i = o.enabledWaves.indexOf(w);
        return typeof o.lineDistance === 'number' ? o.lineDistance : (o.lineDistance[i] ?? 0.04);
      };

      const topCC = getCount('top'), midCC = getCount('middle'), botCC = getCount('bottom');
      const topCD = getDist('top'), midCD = getDist('middle'), botCD = getDist('bottom');

      const hex2v3 = (hex) => {
        const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return r ? new THREE.Vector3(parseInt(r[1],16)/255, parseInt(r[2],16)/255, parseInt(r[3],16)/255) : new THREE.Vector3(1,1,1);
      };

      const scene = new THREE.Scene();
      const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
      camera.position.z = 1;

      const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
      renderer.domElement.style.width = '100%';
      renderer.domElement.style.height = '100%';
      ctn.appendChild(renderer.domElement);

      const uniforms = {
        iTime: { value: 0 }, iResolution: { value: new THREE.Vector3(1,1,1) },
        animationSpeed: { value: o.animationSpeed },
        enableTop: { value: o.enabledWaves.includes('top') },
        enableMiddle: { value: o.enabledWaves.includes('middle') },
        enableBottom: { value: o.enabledWaves.includes('bottom') },
        topLineCount: { value: topCC }, middleLineCount: { value: midCC }, bottomLineCount: { value: botCC },
        topLineDistance: { value: topCD }, middleLineDistance: { value: midCD }, bottomLineDistance: { value: botCD },
        topWavePosition: { value: new THREE.Vector3(10, 0.5, -0.4) },
        middleWavePosition: { value: new THREE.Vector3(5, 0, 0.2) },
        bottomWavePosition: { value: new THREE.Vector3(2, -0.7, 0.4) },
        iMouse: { value: new THREE.Vector2(-1000, -1000) },
        interactive: { value: o.interactive }, bendRadius: { value: o.bendRadius },
        bendStrength: { value: o.bendStrength }, bendInfluence: { value: 0 },
        parallax: { value: o.parallax }, parallaxStrength: { value: o.parallaxStrength },
        parallaxOffset: { value: new THREE.Vector2(0, 0) },
        lineGradient: { value: Array.from({length:4},()=>new THREE.Vector3(1,1,1)) },
        lineGradientCount: { value: 0 }
      };

      if (o.linesGradient?.length) {
        const stops = o.linesGradient.slice(0, 4);
        uniforms.lineGradientCount.value = stops.length;
        stops.forEach((h, i) => uniforms.lineGradient.value[i].copy(hex2v3(h)));
      }

      const mat = new THREE.ShaderMaterial({ uniforms, vertexShader: VERT, fragmentShader: FRAG, transparent: true });
      const geo = new THREE.PlaneGeometry(2, 2);
      scene.add(new THREE.Mesh(geo, mat));

      const clock = new THREE.Clock();
      const resize = () => {
        const w = ctn.clientWidth || 1, h = ctn.clientHeight || 1;
        renderer.setSize(w, h, false);
        uniforms.iResolution.value.set(renderer.domElement.width, renderer.domElement.height, 1);
      };
      window.addEventListener('resize', resize);
      resize();

      const onMove = (e) => {
        const r = renderer.domElement.getBoundingClientRect();
        const dpr = renderer.getPixelRatio();
        this.targetMouse.x = (e.clientX - r.left) * dpr;
        this.targetMouse.y = (r.height - (e.clientY - r.top)) * dpr;
        this.targetMouse.influence = 1;
        if (o.parallax) {
          this.targetParallax.x = ((e.clientX - r.left) / r.width - 0.5) * 2 * o.parallaxStrength;
          this.targetParallax.y = -((e.clientY - r.top) / r.height - 0.5) * 2 * o.parallaxStrength;
        }
      };
      const onLeave = () => { this.targetMouse.influence = 0; };

      if (o.interactive) {
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseleave', onLeave);
      }

      const d = o.mouseDamping;
      const loop = () => {
        this.animId = requestAnimationFrame(loop);
        uniforms.iTime.value = clock.getElapsedTime();

        if (o.interactive) {
          const cm = this.currentMouse, tm = this.targetMouse;
          cm.x += (tm.x - cm.x) * d; cm.y += (tm.y - cm.y) * d;
          cm.influence += (tm.influence - cm.influence) * d;
          uniforms.iMouse.value.set(cm.x, cm.y);
          uniforms.bendInfluence.value = cm.influence;
        }
        if (o.parallax) {
          const cp = this.currentParallax, tp = this.targetParallax;
          cp.x += (tp.x - cp.x) * d; cp.y += (tp.y - cp.y) * d;
          uniforms.parallaxOffset.value.set(cp.x, cp.y);
        }
        renderer.render(scene, camera);
      };
      loop();
    }
  }

  window.FloatingLines = FloatingLines;
})();
