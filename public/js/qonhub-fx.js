/**
 * Qonhub AI 前端特效系统
 * - ClickSpark: 鼠标点击粒子爆发效果
 * - BentoGlow: Bento 卡片辉光跟随效果
 * 零外部依赖，纯原生 JS
 */
(function () {

  // ═══════════════════════════════════════════
  //  ClickSpark — 点击粒子效果
  // ═══════════════════════════════════════════
  class ClickSpark {
    constructor(opts = {}) {
      this.opts = Object.assign({
        sparkColor: '#818cf8', sparkSize: 8, sparkRadius: 20,
        sparkCount: 10, duration: 500, enabled: true
      }, opts);
      this.sparks = [];
      this.startTime = null;
      this.canvas = null;
      this.ctx = null;
      this.animId = null;
      if (this.opts.enabled) this.init();
    }

    init() {
      this.canvas = document.createElement('canvas');
      Object.assign(this.canvas.style, {
        position: 'fixed', inset: '0', zIndex: '9999',
        pointerEvents: 'none', display: 'block'
      });
      document.body.appendChild(this.canvas);
      this.ctx = this.canvas.getContext('2d');

      const resize = () => {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
      };
      window.addEventListener('resize', resize);
      resize();

      document.addEventListener('click', (e) => {
        if (e.target.closest('button, a, input, select, textarea, [data-spark-ignore]')) return;
        this.burst(e.clientX, e.clientY);
      });

      const draw = (t) => {
        this.animId = requestAnimationFrame(draw);
        if (!this.startTime) this.startTime = t;
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        this.sparks = this.sparks.filter(s => {
          const elapsed = t - s.startTime;
          if (elapsed >= this.opts.duration) return false;

          const p = elapsed / this.opts.duration;
          const eased = p * (2 - p); // easeOutQuad
          const dist = eased * this.opts.sparkRadius;
          const len = this.opts.sparkSize * (1 - eased);
          const alpha = 1 - eased;

          const x1 = s.x + dist * Math.cos(s.angle);
          const y1 = s.y + dist * Math.sin(s.angle);
          const x2 = s.x + (dist + len) * Math.cos(s.angle);
          const y2 = s.y + (dist + len) * Math.sin(s.angle);

          this.ctx.strokeStyle = this.opts.sparkColor;
          this.ctx.globalAlpha = alpha;
          this.ctx.lineWidth = 1.5;
          this.ctx.beginPath();
          this.ctx.moveTo(x1, y1);
          this.ctx.lineTo(x2, y2);
          this.ctx.stroke();
          this.ctx.globalAlpha = 1;
          return true;
        });
      };
      draw(0);
    }

    burst(x, y) {
      const now = performance.now();
      for (let i = 0; i < this.opts.sparkCount; i++) {
        this.sparks.push({
          x, y,
          angle: (2 * Math.PI * i) / this.opts.sparkCount + (Math.random() - 0.5) * 0.5,
          startTime: now + Math.random() * 50
        });
      }
    }
  }

  // ═══════════════════════════════════════════
  //  BentoGlow — 卡片辉光跟随
  // ═══════════════════════════════════════════
  class BentoGlow {
    constructor(selector = '.bento-card', opts = {}) {
      this.opts = Object.assign({
        glowColor: '99, 102, 241', spotlightRadius: 350,
        borderGlow: true, particleBurst: true, enabled: true
      }, opts);
      if (!this.opts.enabled) return;
      this.cards = document.querySelectorAll(selector);
      this.createSpotlight();
      this.bindEvents();
    }

    createSpotlight() {
      this.spot = document.createElement('div');
      Object.assign(this.spot.style, {
        position: 'fixed', pointerEvents: 'none', zIndex: '100',
        width: '600px', height: '600px', borderRadius: '50%',
        background: `radial-gradient(circle, rgba(${this.opts.glowColor}, 0.12) 0%, rgba(${this.opts.glowColor}, 0.04) 30%, transparent 60%)`,
        transform: 'translate(-50%, -50%)', opacity: '0',
        mixBlendMode: 'screen'
      });
      document.body.appendChild(this.spot);
    }

    bindEvents() {
      document.addEventListener('mousemove', (e) => {
        if (!this.spot) return;
        this.spot.style.left = e.clientX + 'px';
        this.spot.style.top = e.clientY + 'px';

        let nearCard = false;
        this.cards.forEach(card => {
          const r = card.getBoundingClientRect();
          const cx = r.left + r.width / 2;
          const cy = r.top + r.height / 2;
          const dist = Math.hypot(e.clientX - cx, e.clientY - cy);
          const maxDist = Math.max(r.width, r.height);
          const glow = dist < maxDist ? 1 - (dist / maxDist) : 0;
          card.style.setProperty('--glow-x', ((e.clientX - r.left) / r.width * 100) + '%');
          card.style.setProperty('--glow-y', ((e.clientY - r.top) / r.height * 100) + '%');
          card.style.setProperty('--glow-intensity', glow);
          if (glow > 0.1) nearCard = true;
        });

        this.spot.style.opacity = nearCard ? '1' : '0';
      });

      document.addEventListener('mouseleave', () => {
        if (this.spot) this.spot.style.opacity = '0';
        this.cards.forEach(c => { c.style.setProperty('--glow-intensity', '0'); });
      });
    }
  }

  window.QonhubFX = { ClickSpark, BentoGlow };
})();
