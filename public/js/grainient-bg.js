/**
 * Qonhub AI Gradient Background — 原生 WebGL 流体渐变
 * 适配自 React Bits Grainient 组件，零外部依赖
 */
(function () {
  const VERT = `#version 300 es
in vec2 position;
void main(){gl_Position=vec4(position,0.,1.);}`;

  const FRAG = `#version 300 es
precision highp float;
uniform vec2 iResolution;
uniform float iTime;
uniform float uTimeSpeed;
uniform float uColorBalance;
uniform float uWarpStrength;
uniform float uWarpFrequency;
uniform float uWarpSpeed;
uniform float uWarpAmplitude;
uniform float uBlendAngle;
uniform float uBlendSoftness;
uniform float uRotationAmount;
uniform float uNoiseScale;
uniform float uGrainAmount;
uniform float uGrainScale;
uniform float uGrainAnimated;
uniform float uContrast;
uniform float uGamma;
uniform float uSaturation;
uniform vec2 uCenterOffset;
uniform float uZoom;
uniform vec3 uColor1;
uniform vec3 uColor2;
uniform vec3 uColor3;
out vec4 fragColor;

mat2 Rot(float a){float s=sin(a),c=cos(a);return mat2(c,-s,s,c);}
vec2 hash(vec2 p){p=vec2(dot(p,vec2(2127.1,81.17)),dot(p,vec2(1269.5,283.37)));return fract(sin(p)*43758.5453);}
float noise(vec2 p){vec2 i=floor(p),f=fract(p),u=f*f*(3.-2.*f);float n=mix(mix(dot(-1.+2.*hash(i+vec2(0,0)),f-vec2(0,0)),dot(-1.+2.*hash(i+vec2(1,0)),f-vec2(1,0)),u.x),mix(dot(-1.+2.*hash(i+vec2(0,1)),f-vec2(0,1)),dot(-1.+2.*hash(i+vec2(1,1)),f-vec2(1,1)),u.x),u.y);return.5+.5*n;}

void mainImage(out vec4 o,vec2 C){
  float t=iTime*uTimeSpeed;
  vec2 uv=C/iResolution.xy;
  float ratio=iResolution.x/iResolution.y;
  vec2 tuv=uv-.5+uCenterOffset;
  tuv/=max(uZoom,.001);

  float degree=noise(vec2(t*.1,tuv.x*tuv.y)*uNoiseScale);
  tuv.y*=1./ratio;
  tuv*=Rot(radians((degree-.5)*uRotationAmount+180.));
  tuv.y*=ratio;

  float freq=uWarpFrequency;
  float ws=max(uWarpStrength,.001);
  float amplitude=uWarpAmplitude/ws;
  float warpTime=t*uWarpSpeed;
  tuv.x+=sin(tuv.y*freq+warpTime)/amplitude;
  tuv.y+=sin(tuv.x*(freq*1.5)+warpTime)/(amplitude*.5);

  vec3 colLav=uColor1;
  vec3 colOrg=uColor2;
  vec3 colDark=uColor3;
  float b=uColorBalance;
  float s=max(uBlendSoftness,0.);
  mat2 blendRot=Rot(radians(uBlendAngle));
  float blendX=(tuv*blendRot).x;
  float v0=.5-b+s;
  float v1=-.3-b-s;
  vec3 layer1=mix(colDark,colOrg,smoothstep(-.3-b-s,.2-b+s,blendX));
  vec3 layer2=mix(colOrg,colLav,smoothstep(-.3-b-s,.2-b+s,blendX));
  vec3 col=mix(layer1,layer2,smoothstep(v0,v1,tuv.y));

  vec2 grainUv=uv*max(uGrainScale,.001);
  if(uGrainAnimated>.5) grainUv+=vec2(iTime*.05);
  float grain=fract(sin(dot(grainUv,vec2(12.9898,78.233)))*43758.5453);
  col+=(grain-.5)*uGrainAmount;

  col=(col-.5)*uContrast+.5;
  float luma=dot(col,vec3(.2126,.7152,.0722));
  col=mix(vec3(luma),col,uSaturation);
  col=pow(max(col,0.),vec3(1./max(uGamma,.001)));
  col=clamp(col,0.,1.);
  o=vec4(col,1.);
}
void main(){vec4 o=vec4(0.);mainImage(o,gl_FragCoord.xy);fragColor=o;}`;

  class GrainientBackground {
    constructor(container, opts = {}) {
      this.container = container;
      this.opts = Object.assign({
        color1: '#a5b4fc', color2: '#6366f1', color3: '#1e1b4b',
        timeSpeed: 0.2, warpStrength: 1.0, warpFrequency: 3.0,
        warpSpeed: 1.5, warpAmplitude: 60.0, blendAngle: 0,
        blendSoftness: 0.08, rotationAmount: 400, noiseScale: 2.0,
        grainAmount: 0.06, grainScale: 2.0, grainAnimated: false,
        contrast: 1.3, gamma: 1.0, saturation: 1.1,
        centerX: 0.0, centerY: 0.0, zoom: 1.0
      }, opts);

      this.init();
    }

    init() {
      const ctn = this.container;
      ctn.style.position = 'fixed';
      ctn.style.inset = '0';
      ctn.style.zIndex = '0';
      ctn.style.pointerEvents = 'none';

      const canvas = document.createElement('canvas');
      canvas.style.width = '100%';
      canvas.style.height = '100%';
      ctn.appendChild(canvas);

      const gl = canvas.getContext('webgl2');
      if (!gl) { console.warn('WebGL2 not available'); return; }

      const program = this.createProgram(gl, VERT, FRAG);
      const positions = new Float32Array([-1, -1, 3, -1, -1, 3]);
      this.setupBuffer(gl, program, 'position', positions, 2);

      const u = (name, val) => { const l = gl.getUniformLocation(program, name); if (l) this.setUniform(gl, l, val); };

      const resize = () => {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const w = ctn.clientWidth * dpr;
        const h = ctn.clientHeight * dpr;
        if (canvas.width !== w || canvas.height !== h) {
          canvas.width = w;
          canvas.height = h;
          gl.viewport(0, 0, w, h);
          u('iResolution', [w, h]);
        }
      };
      window.addEventListener('resize', resize);
      resize();

      const hex2rgb = (hex) => {
        const r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return r ? [parseInt(r[1],16)/255, parseInt(r[2],16)/255, parseInt(r[3],16)/255] : [1,1,1];
      };

      const o = this.opts;
      u('uColor1', hex2rgb(o.color1));
      u('uColor2', hex2rgb(o.color2));
      u('uColor3', hex2rgb(o.color3));
      u('uTimeSpeed', o.timeSpeed);
      u('uColorBalance', o.colorBalance ?? 0);
      u('uWarpStrength', o.warpStrength);
      u('uWarpFrequency', o.warpFrequency);
      u('uWarpSpeed', o.warpSpeed);
      u('uWarpAmplitude', o.warpAmplitude);
      u('uBlendAngle', o.blendAngle ?? 0);
      u('uBlendSoftness', o.blendSoftness ?? 0.05);
      u('uRotationAmount', o.rotationAmount ?? 500);
      u('uNoiseScale', o.noiseScale ?? 2);
      u('uGrainAmount', o.grainAmount ?? 0.1);
      u('uGrainScale', o.grainScale ?? 2);
      u('uGrainAnimated', o.grainAnimated ? 1 : 0);
      u('uContrast', o.contrast ?? 1.5);
      u('uGamma', o.gamma ?? 1);
      u('uSaturation', o.saturation ?? 1);
      u('uCenterOffset', [o.centerX ?? 0, o.centerY ?? 0]);
      u('uZoom', o.zoom ?? 0.9);

      let raf, visible = true, pageVisible = !document.hidden;
      const t0 = performance.now();

      const loop = (t) => {
        u('iTime', (t - t0) * 0.001);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
        raf = requestAnimationFrame(loop);
      };

      const tryStart = () => { if (visible && pageVisible && !raf) raf = requestAnimationFrame(loop); };
      const tryStop = () => { if (raf) { cancelAnimationFrame(raf); raf = 0; } };

      const io = new IntersectionObserver(([e]) => { visible = e.isIntersecting; visible ? tryStart() : tryStop(); }, { threshold: 0 });
      io.observe(ctn);

      document.addEventListener('visibilitychange', () => {
        pageVisible = !document.hidden;
        pageVisible ? tryStart() : tryStop();
      });

      tryStart();
    }

    createProgram(gl, vs, fs) {
      const compile = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); return s; };
      const p = gl.createProgram();
      gl.attachShader(p, compile(gl.VERTEX_SHADER, vs));
      gl.attachShader(p, compile(gl.FRAGMENT_SHADER, fs));
      gl.linkProgram(p);
      gl.useProgram(p);
      return p;
    }

    setupBuffer(gl, prog, name, data, size) {
      const buf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, buf);
      gl.bufferData(gl.ARRAY_BUFFER, data, gl.STATIC_DRAW);
      const loc = gl.getAttribLocation(prog, name);
      gl.enableVertexAttribArray(loc);
      gl.vertexAttribPointer(loc, size, gl.FLOAT, false, 0, 0);
    }

    setUniform(gl, loc, val) {
      if (typeof val === 'number') gl.uniform1f(loc, val);
      else if (val.length === 2) gl.uniform2fv(loc, val);
      else if (val.length === 3) gl.uniform3fv(loc, val);
      else if (typeof val === 'boolean') gl.uniform1i(loc, val ? 1 : 0);
    }
  }

  window.GrainientBackground = GrainientBackground;
})();
