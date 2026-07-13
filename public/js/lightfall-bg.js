/**
 * Qonhub AI Lightfall — WebGL2 光雨背景
 */
(function(){
const V=`#version 300 es
in vec2 p;in vec2 uv;out vec2 v;void main(){v=uv;gl_Position=vec4(p,0.,1.);}`;

const F=`#version 300 es
precision highp float;
uniform vec3 R;uniform vec2 M;uniform float T;
uniform vec3 c0,c1,c2,c3,c4,c5,c6,c7;uniform int cN;
uniform vec3 bg,mc;uniform float sp,sw,sl,gl,de,tw,zm,bgG,op,me,ms,mr;
uniform int uN;
in vec2 v;out vec4 o;

vec3 pal(float h){int i=int(floor(clamp(h,0.,.9999)*float(max(cN,1))));
if(i<=0)return c0;if(i==1)return c1;if(i==2)return c2;if(i==3)return c3;
if(i==4)return c4;if(i==5)return c5;if(i==6)return c6;return c7;}
vec3 th(vec3 x){vec3 e=exp(-2.*x);return(1.-e)/(1.+e);}
vec2 sc(vec2 f,vec2 r){vec2 P=(f+f-r)/r.x;float z=0.,d=1e3;vec4 O=vec4(0.);
for(int k=0;k<36;k++){if(d<=1e-4)break;O=z*normalize(vec4(P,zm,0.))-vec4(0.,4.,1.,0.)/4.5;d=1.-sqrt(length(O*O));z+=d;}
return vec2(O.x,atan(O.z,O.y));}

void main(){
  vec2 r=R.xy;vec2 C=v*r;
  float t=.1*T*sp+9.;
  float ar=max(1.,floor(6.283*max(de,.05)+.5));
  vec2 Y=vec2(.005,6.283/ar);
  vec2 c=sc(C,r),cx=sc(C+vec2(1.,0.),r),cy=sc(C+vec2(0.,1.),r);
  cx.y-=6.283*floor(cx.y/6.283+.5);cy.y-=6.283*floor(cy.y/6.283+.5);
  vec2 fw=abs(cx-c)+abs(cy-c);C=c;
  vec2 uv0=(C+C-r)/r.x;
  vec4 O=vec4(bg*90.*bgG/(1e3*dot(vec2(2.,1.)*uv0-(r/r.x)*vec2(0.,1.),vec2(2.,1.)*uv0-(r/r.x)*vec2(0.,1.))+6.),0.);
  float mG=0.;
  if(me>.5){vec2 mN=(M+M-r)/r.x;float md=length(uv0-mN);mG=exp(-md*md/max(mr*mr,1e-4))*ms;O.rgb+=mc*mG*.25;}
  float zr=.0005*sw;vec2 rr=vec2(max(length(fw),1e-5));float tail=19./max(sl,.05);
  for(int m=0;m<16;m++){
    if(m>=uN)break;
    float j=float(m)+1.;
    float ic=fract(sin(dot(vec2(j,floor(C.x/Y.x+.5)),vec2(7.,11.))*73.));
    vec2 Pp=C-(t+t*ic)*vec2(0.,1.);Pp-=floor(Pp/Y+.5)*Y;
    float h=fract(8663.*ic);
    float w=mix(1.5,1.+sin(t+7.*h+4.),tw)*(1.+mG*2.);
    vec2 i2=vec2(length(max(Pp,vec2(-1.,0.))),length(Pp)-zr)-zr;
    vec2 sm=vec2(1.)-smoothstep(-rr,rr,i2);
    O.rgb+=dot(sm,vec2(exp(tail*Pp.y),3.))*pal(h)*w;
    C.x+=Y.x/8.;
  }
  o=vec4(sqrt(th(max(O.rgb*gl-vec3(.04,.08,.02),0.))),op);
}`;

const MAX=8;
function h2r(h){const c=h.replace('#','').padEnd(6,'0');return[parseInt(c.slice(0,2),16)/255,parseInt(c.slice(2,4),16)/255,parseInt(c.slice(4,6),16)/255];}
function use(gl,p,name,val){
  const l=gl.getUniformLocation(p,name);if(!l)return;
  if(Number.isInteger(val)&&typeof val==='number')gl.uniform1i(l,val);
  else if(typeof val==='number')gl.uniform1f(l,val);
  else if(val.length===2)gl.uniform2fv(l,val);
  else if(val.length===3)gl.uniform3fv(l,val);
  else if(typeof val==='boolean')gl.uniform1i(l,val?1:0);
}

class LightfallBackground{
  constructor(ctn,opts={}){
    this.ctn=ctn;this.mPos=[0,0];this.sPos=[0,0];this.lt=0;this.raf=0;
    this.opts=Object.assign({
      colors:['#c4b5fd','#818cf8','#6366f1'],backgroundColor:'#0d0e1a',
      speed:.4,streakCount:3,streakWidth:.8,streakLength:1.2,glow:1.1,
      density:.5,twinkle:.8,zoom:3.5,backgroundGlow:.35,opacity:.8,
      mouseInteraction:true,mouseStrength:.35,mouseRadius:1.2,mouseDampening:.12,
    },opts);
    this.init();
  }
  init(){
    const c=this.ctn;c.style.cssText='position:fixed;inset:0;z-index:0;pointer-events:none;';
    const canvas=document.createElement('canvas');canvas.style.cssText='width:100%;height:100%;display:block;';
    c.appendChild(canvas);
    const gl=canvas.getContext('webgl2');if(!gl){c.innerHTML='<div style="color:rgba(255,255,255,.3);text-align:center;padding-top:40vh">浏览器不支持WebGL2</div>';return;}

    const vs=gl.createShader(gl.VERTEX_SHADER);gl.shaderSource(vs,V);gl.compileShader(vs);
    const fs=gl.createShader(gl.FRAGMENT_SHADER);gl.shaderSource(fs,F);gl.compileShader(fs);
    if(!gl.getShaderParameter(fs,gl.COMPILE_STATUS)){console.warn('Lightfall shader error:',gl.getShaderInfoLog(fs));}
    const p=gl.createProgram();gl.attachShader(p,vs);gl.attachShader(p,fs);gl.linkProgram(p);gl.useProgram(p);

    const buf=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,buf);
    gl.bufferData(gl.ARRAY_BUFFER,new Float32Array([-1,-1,0,0,3,-1,2,0,-1,3,0,2]),gl.STATIC_DRAW);
    const ap=gl.getAttribLocation(p,'p');gl.enableVertexAttribArray(ap);gl.vertexAttribPointer(ap,2,gl.FLOAT,!1,16,0);
    const au=gl.getAttribLocation(p,'uv');if(au>=0){gl.enableVertexAttribArray(au);gl.vertexAttribPointer(au,2,gl.FLOAT,!1,16,8);}

    const o=this.opts;
    const base=o.colors&&o.colors.length?o.colors:['#c4b5fd','#818cf8','#6366f1'];
    const arr=[];for(let i=0;i<MAX;i++)arr.push(h2r(base[Math.min(i,base.length-1)]));
    const bg=h2r(o.backgroundColor);
    const avg=[0,0,0];for(let i=0;i<base.length;i++){avg[0]+=arr[i][0];avg[1]+=arr[i][1];avg[2]+=arr[i][2];}
    avg[0]/=base.length;avg[1]/=base.length;avg[2]/=base.length;

    const u=use.bind(null,gl,p);
    u('c0',arr[0]);u('c1',arr[1]);u('c2',arr[2]);u('c3',arr[3]);u('c4',arr[4]);u('c5',arr[5]);u('c6',arr[6]);u('c7',arr[7]);
    u('cN',base.length);u('bg',bg);u('mc',avg);
    u('uN',Math.max(1,Math.min(16,Math.round(o.streakCount))));
    u('sp',o.speed);u('sw',o.streakWidth);u('sl',o.streakLength);u('gl',o.glow);
    u('de',o.density);u('tw',o.twinkle);u('zm',o.zoom);u('bgG',o.backgroundGlow);u('op',o.opacity);
    u('me',o.mouseInteraction?1:0);u('ms',o.mouseStrength);u('mr',o.mouseRadius);

    const resize=()=>{
      const dpr=Math.min(window.devicePixelRatio||1,2);
      const w=c.clientWidth*dpr,h=c.clientHeight*dpr;
      if(canvas.width!==w||canvas.height!==h){
        canvas.width=w;canvas.height=h;gl.viewport(0,0,w,h);
        u('R',[w,h,1]);
      }
    };
    window.addEventListener('resize',resize);resize();

    if(o.mouseInteraction){
      document.addEventListener('mousemove',e=>{
        this.mPos=[e.clientX*2,(window.innerHeight-e.clientY)*2];
      });
    }

    const loop=(t)=>{
      this.raf=requestAnimationFrame(loop);
      const dt=this.lt?(t-this.lt)/1000:0;this.lt=t;
      u('T',t*.001);
      if(o.mouseDampening>0&&dt>0){
        const tau=Math.max(1e-4,o.mouseDampening),f=Math.min(1-Math.exp(-dt/tau),1);
        this.sPos[0]+=(this.mPos[0]-this.sPos[0])*f;
        this.sPos[1]+=(this.mPos[1]-this.sPos[1])*f;
      }else{
        this.sPos[0]=this.mPos[0];this.sPos[1]=this.mPos[1];
      }
      u('M',this.sPos);
      gl.drawArrays(gl.TRIANGLES,0,3);
    };
    this.raf=requestAnimationFrame(loop);
  }
}
window.LightfallBackground=LightfallBackground;
})();
