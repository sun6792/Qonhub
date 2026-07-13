/**
 * Qonhub AI Galaxy Background — 原生 WebGL 星空背景
 * 适配自 React Bits Galaxy 组件，零外部依赖
 * 使用: new GalaxyBackground(document.getElementById('galaxy-container'))
 */
(function () {
  const VERTEX = `attribute vec2 uv;attribute vec2 position;varying vec2 vUv;void main(){vUv=uv;gl_Position=vec4(position,0,1);}`;

  const FRAGMENT = `precision highp float;uniform float uTime;uniform vec3 uResolution;uniform vec2 uFocal;uniform vec2 uRotation;uniform float uStarSpeed;uniform float uDensity;uniform float uHueShift;uniform float uSpeed;uniform vec2 uMouse;uniform float uGlowIntensity;uniform float uSaturation;uniform bool uMouseRepulsion;uniform float uTwinkleIntensity;uniform float uRotationSpeed;uniform float uRepulsionStrength;uniform float uMouseActiveFactor;uniform float uAutoCenterRepulsion;uniform bool uTransparent;varying vec2 vUv;
#define NUM_LAYER 4.0
#define MAT45 mat2(0.7071,-0.7071,0.7071,0.7071)
#define PERIOD 3.0
float Hash21(vec2 p){p=fract(p*vec2(123.34,456.21));p+=dot(p,p+45.32);return fract(p.x*p.y);}
float tri(float x){return abs(fract(x)*2.-1.);}
float tris(float x){float t=fract(x);return 1.-smoothstep(0.,1.,abs(2.*t-1.));}
float trisn(float x){float t=fract(x);return 2.*(1.-smoothstep(0.,1.,abs(2.*t-1.)))-1.;}
vec3 hsv2rgb(vec3 c){vec4 K=vec4(1.,2./3.,1./3.,3.);vec3 p=abs(fract(c.xxx+K.xyz)*6.-K.www);return c.z*mix(K.xxx,clamp(p-K.xxx,0.,1.),c.y);}
float Star(vec2 uv,float flare){float d=length(uv);float m=(.05*uGlowIntensity)/d;float rays=smoothstep(0.,1.,1.-abs(uv.x*uv.y*1000.));m+=rays*flare*uGlowIntensity;uv*=MAT45;rays=smoothstep(0.,1.,1.-abs(uv.x*uv.y*1000.));m+=rays*.3*flare*uGlowIntensity;m*=smoothstep(1.,.2,d);return m;}
vec3 StarLayer(vec2 uv){vec3 col=vec3(0.);vec2 gv=fract(uv)-.5;vec2 id=floor(uv);
for(int y=-1;y<=1;y++){for(int x=-1;x<=1;x++){vec2 offset=vec2(float(x),float(y));vec2 si=id+offset;float seed=Hash21(si);float size=fract(seed*345.32);float glossLocal=tri(uStarSpeed/(PERIOD*seed+1.));float flareSize=smoothstep(.9,1.,size)*glossLocal;
float red=smoothstep(.2,1.,Hash21(si+1.))+.2;float blu=smoothstep(.2,1.,Hash21(si+3.))+.2;float grn=min(red,blu)*seed;vec3 base=vec3(red,grn,blu);
float hue=atan(base.g-base.r,base.b-base.r)/(6.28318)+.5;hue=fract(hue+uHueShift/360.);float sat=length(base-vec3(dot(base,vec3(.299,.587,.114))))*uSaturation;float val=max(max(base.r,base.g),base.b);base=hsv2rgb(vec3(hue,sat,val));
vec2 pad=vec2(tris(seed*34.+uTime*uSpeed/10.),tris(seed*38.+uTime*uSpeed/30.))-.5;
float star=Star(gv-offset-pad,flareSize);vec3 color=base;
float twinkle=trisn(uTime*uSpeed+seed*6.2831)*.5+1.;twinkle=mix(1.,twinkle,uTwinkleIntensity);star*=twinkle;
col+=star*size*color;}}return col;}
void main(){vec2 focalPx=uFocal*uResolution.xy;vec2 uv=(vUv*uResolution.xy-focalPx)/uResolution.y;
vec2 mouseNorm=uMouse-vec2(.5);
if(uAutoCenterRepulsion>0.){vec2 centerUV=vec2(0.);float centerDist=length(uv-centerUV);vec2 repulsion=normalize(uv-centerUV)*(uAutoCenterRepulsion/(centerDist+.1));uv+=repulsion*.05;}
else if(uMouseRepulsion){vec2 mousePosUV=(uMouse*uResolution.xy-focalPx)/uResolution.y;float mouseDist=length(uv-mousePosUV);vec2 repulsion=normalize(uv-mousePosUV)*(uRepulsionStrength/(mouseDist+.1));uv+=repulsion*.05*uMouseActiveFactor;}
else{vec2 mouseOffset=mouseNorm*.1*uMouseActiveFactor;uv+=mouseOffset;}
float autoRotAngle=uTime*uRotationSpeed;mat2 autoRot=mat2(cos(autoRotAngle),-sin(autoRotAngle),sin(autoRotAngle),cos(autoRotAngle));uv=autoRot*uv;
uv=mat2(uRotation.x,-uRotation.y,uRotation.y,uRotation.x)*uv;
vec3 col=vec3(0.);
for(float i=0.;i<1.;i+=1./NUM_LAYER){float depth=fract(i+uStarSpeed*uSpeed);float scale=mix(20.*uDensity,.5*uDensity,depth);float fade=depth*smoothstep(1.,.9,depth);col+=StarLayer(uv*scale+i*453.32)*fade;}
if(uTransparent){float alpha=length(col);alpha=smoothstep(0.,.3,alpha);alpha=min(alpha,1.);gl_FragColor=vec4(col,alpha);}
else{gl_FragColor=vec4(col,1.);}}`;

  class GalaxyBackground {
    constructor(container, opts = {}) {
      this.container = container;
      this.opts = Object.assign({
        density: 1.5, glowIntensity: 0.4, saturation: 0.3,
        hueShift: 140, twinkleIntensity: 0.2, rotationSpeed: 0.08,
        starSpeed: 0.3, speed: 0.8, mouseRepulsion: true,
        repulsionStrength: 1.5, mouseInteraction: true, transparent: true,
        focal: [0.5, 0.5], rotation: [1.0, 0.0]
      }, opts);

      this.targetMouse = { x: 0.5, y: 0.5 };
      this.smoothMouse = { x: 0.5, y: 0.5 };
      this.targetActive = 0;
      this.smoothActive = 0;
      this.animId = null;

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

      const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
      if (!gl) { console.warn('WebGL not available for galaxy background'); return; }

      const o = this.opts;
      if (o.transparent) {
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
        gl.clearColor(0, 0, 0, 0);
      }

      const program = this.createProgram(gl, VERTEX, FRAGMENT);
      const positions = new Float32Array([-1, -1, 3, -1, -1, 3]);
      const uvs = new Float32Array([0, 0, 2, 0, 0, 2]);
      this.setupBuffer(gl, program, 'position', positions, 2);
      this.setupBuffer(gl, program, 'uv', uvs, 2);

      const u = (name, val) => { const l = gl.getUniformLocation(program, name); if (l) this.setUniform(gl, l, val); };

      const resize = () => {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const w = ctn.clientWidth * dpr;
        const h = ctn.clientHeight * dpr;
        if (canvas.width !== w || canvas.height !== h) {
          canvas.width = w;
          canvas.height = h;
          gl.viewport(0, 0, w, h);
          u('uResolution', [w, h, w / h]);
        }
      };
      window.addEventListener('resize', resize);
      resize();

      u('uFocal', o.focal);
      u('uRotation', o.rotation);
      u('uDensity', o.density);
      u('uHueShift', o.hueShift);
      u('uSpeed', o.speed);
      u('uGlowIntensity', o.glowIntensity);
      u('uSaturation', o.saturation);
      u('uMouseRepulsion', o.mouseRepulsion);
      u('uTwinkleIntensity', o.twinkleIntensity);
      u('uRotationSpeed', o.rotationSpeed);
      u('uRepulsionStrength', o.repulsionStrength);
      u('uAutoCenterRepulsion', 0);
      u('uTransparent', o.transparent);

      const handleMove = (e) => {
        const r = ctn.getBoundingClientRect();
        this.targetMouse = { x: (e.clientX - r.left) / r.width, y: 1 - (e.clientY - r.top) / r.height };
        this.targetActive = 1;
      };
      const handleLeave = () => { this.targetActive = 0; };

      if (o.mouseInteraction) {
        document.addEventListener('mousemove', handleMove);
        document.addEventListener('mouseleave', handleLeave);
      }

      const render = (t) => {
        this.animId = requestAnimationFrame(render);
        const sec = t * 0.001;
        u('uTime', sec);
        u('uStarSpeed', sec * o.starSpeed / 10);

        const l = 0.05;
        this.smoothMouse.x += (this.targetMouse.x - this.smoothMouse.x) * l;
        this.smoothMouse.y += (this.targetMouse.y - this.smoothMouse.y) * l;
        this.smoothActive += (this.targetActive - this.smoothActive) * l;

        u('uMouse', [this.smoothMouse.x, this.smoothMouse.y]);
        u('uMouseActiveFactor', this.smoothActive);

        gl.drawArrays(gl.TRIANGLES, 0, 3);
      };
      render(0);
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

    destroy() {
      if (this.animId) cancelAnimationFrame(this.animId);
    }
  }

  window.GalaxyBackground = GalaxyBackground;
})();
