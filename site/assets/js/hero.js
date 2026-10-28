/*
 * Copyright (c) 2026 erik <erik@erik.xyz> https://erik.xyz
 * open-admin hero scene: a shield-shaped guard field around Xiao An
 */
const stage = document.getElementById('three-stage');

if (stage) {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  const start = async () => {
    try {
      const THREE = await import('../vendor/three.module.min.js');
      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
      camera.position.set(0, 0.1, 5.2);
      camera.lookAt(0, 0, 0);

      const renderer = new THREE.WebGLRenderer({
        alpha: true,
        antialias: true,
        powerPreference: 'high-performance'
      });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
      renderer.setClearColor(0x000000, 0);
      renderer.outputColorSpace = THREE.SRGBColorSpace;
      stage.appendChild(renderer.domElement);

      const world = new THREE.Group();
      scene.add(world);

      const shieldShape = new THREE.Shape();
      shieldShape.moveTo(-0.72, 0.98);
      shieldShape.lineTo(0.72, 0.98);
      shieldShape.quadraticCurveTo(0.95, 0.98, 0.95, 0.7);
      shieldShape.lineTo(0.95, 0.3);
      shieldShape.bezierCurveTo(0.95, -0.48, 0.5, -0.95, 0, -1.22);
      shieldShape.bezierCurveTo(-0.5, -0.95, -0.95, -0.48, -0.95, 0.3);
      shieldShape.lineTo(-0.95, 0.7);
      shieldShape.quadraticCurveTo(-0.95, 0.98, -0.72, 0.98);

      const shieldGeometry = new THREE.ExtrudeGeometry(shieldShape, {
        depth: 0.12,
        bevelEnabled: true,
        bevelThickness: 0.035,
        bevelSize: 0.035,
        bevelSegments: 2,
        steps: 1
      });
      shieldGeometry.center();

      const shield = new THREE.Mesh(
        shieldGeometry,
        new THREE.MeshStandardMaterial({
          color: 0x1677ff,
          emissive: 0x0b3e9e,
          emissiveIntensity: 0.55,
          metalness: 0.35,
          roughness: 0.22,
          transparent: true,
          opacity: 0.13,
          depthWrite: false
        })
      );
      shield.scale.setScalar(1.85);
      world.add(shield);

      const edgeMaterial = new THREE.LineBasicMaterial({
        color: 0x4d9bff,
        transparent: true,
        opacity: 0.72
      });
      const shieldEdge = new THREE.LineSegments(
        new THREE.EdgesGeometry(shieldGeometry, 20),
        edgeMaterial
      );
      shieldEdge.scale.copy(shield.scale);
      world.add(shieldEdge);

      const orbitMaterial = new THREE.LineBasicMaterial({
        color: 0x1677ff,
        transparent: true,
        opacity: 0.28
      });

      const orbits = [];
      [
        { radius: 1.55, rotation: [1.22, 0.1, 0.2], opacity: 0.36 },
        { radius: 1.95, rotation: [1.36, 0.45, -0.18], opacity: 0.24 },
        { radius: 2.3, rotation: [1.08, -0.35, 0.42], opacity: 0.16 }
      ].forEach((item, index) => {
        const material = orbitMaterial.clone();
        material.opacity = item.opacity;
        const orbit = new THREE.LineLoop(
          new THREE.TorusGeometry(item.radius, 0.0035, 4, 180),
          material
        );
        orbit.rotation.set(...item.rotation);
        orbit.userData.phase = index * 0.9;
        world.add(orbit);
        orbits.push(orbit);
      });

      const nodeGeometry = new THREE.SphereGeometry(0.032, 10, 10);
      const nodeMaterials = [
        new THREE.MeshBasicMaterial({ color: 0x52c41a }),
        new THREE.MeshBasicMaterial({ color: 0xfa8c16 }),
        new THREE.MeshBasicMaterial({ color: 0x6fb4ff })
      ];
      const nodes = [];
      [
        [1.55, 0.0, 0.36, 0],
        [1.95, 2.2, 0.24, 1],
        [2.3, 4.1, 0.16, 2]
      ].forEach(([radius, angle, , materialIndex]) => {
        const node = new THREE.Mesh(nodeGeometry, nodeMaterials[materialIndex]);
        node.userData.radius = radius;
        node.userData.angle = angle;
        node.userData.speed = 0.16 + materialIndex * 0.04;
        world.add(node);
        nodes.push(node);
      });

      const count = window.innerWidth < 720 ? 220 : 420;
      const positions = new Float32Array(count * 3);
      const colors = new Float32Array(count * 3);
      const blue = new THREE.Color(0x4d9bff);
      const amber = new THREE.Color(0xfa8c16);

      for (let i = 0; i < count; i += 1) {
        const radius = 2.2 + Math.random() * 2.6;
        const theta = Math.random() * Math.PI * 2;
        const phi = Math.acos(2 * Math.random() - 1);
        positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
        positions[i * 3 + 1] = radius * Math.cos(phi) * 0.85;
        positions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);

        const color = Math.random() > 0.88 ? amber : blue;
        colors[i * 3] = color.r;
        colors[i * 3 + 1] = color.g;
        colors[i * 3 + 2] = color.b;
      }

      const particleGeometry = new THREE.BufferGeometry();
      particleGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
      particleGeometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

      const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      const particles = new THREE.Points(
        particleGeometry,
        new THREE.PointsMaterial({
          size: 0.032,
          vertexColors: true,
          transparent: true,
          opacity: isDark ? 0.82 : 0.58,
          blending: isDark ? THREE.AdditiveBlending : THREE.NormalBlending,
          depthWrite: false,
          sizeAttenuation: true
        })
      );
      world.add(particles);

      scene.add(new THREE.AmbientLight(0xbcd8ff, 1.3));
      const keyLight = new THREE.DirectionalLight(0xffffff, 2.2);
      keyLight.position.set(3, 4, 6);
      scene.add(keyLight);
      const blueLight = new THREE.PointLight(0x1677ff, 22, 12, 2);
      blueLight.position.set(-3, -1, 3);
      scene.add(blueLight);
      const warmLight = new THREE.PointLight(0xfa8c16, 15, 10, 2);
      warmLight.position.set(3, -2, 2);
      scene.add(warmLight);

      const pointer = { x: 0, y: 0 };
      const target = { x: 0, y: 0 };
      const clock = new THREE.Clock();
      let visible = true;
      let running = true;

      const resize = () => {
        const width = stage.clientWidth;
        const height = stage.clientHeight;
        if (!width || !height) return;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
      };

      const render = () => {
        const elapsed = clock.getElapsedTime();

        if (!reduceMotion.matches) {
          world.rotation.y = Math.sin(elapsed * 0.22) * 0.14 + target.x * 0.2;
          world.rotation.x = Math.cos(elapsed * 0.18) * 0.06 + target.y * 0.12;
          shield.rotation.z = Math.sin(elapsed * 0.35) * 0.025;
          shieldEdge.rotation.z = shield.rotation.z;
          particles.rotation.y = elapsed * 0.018;
          particles.rotation.x = Math.sin(elapsed * 0.1) * 0.08;

          orbits.forEach((orbit) => {
            orbit.rotation.z += 0.0009;
          });

          nodes.forEach((node) => {
            const angle = node.userData.angle + elapsed * node.userData.speed;
            node.position.set(
              Math.cos(angle) * node.userData.radius,
              Math.sin(angle) * node.userData.radius * 0.36,
              Math.sin(angle) * node.userData.radius * 0.7
            );
          });

          target.x = pointer.x * 0.16;
          target.y = pointer.y * 0.12;
        }

        renderer.render(scene, camera);
      };

      const loop = () => {
        if (!running || !visible) return;
        render();
      };

      resize();
      const resizeObserver = new ResizeObserver(resize);
      resizeObserver.observe(stage);

      if ('IntersectionObserver' in window) {
        const stageObserver = new IntersectionObserver(([entry]) => {
          visible = entry.isIntersecting;
        }, { rootMargin: '120px' });
        stageObserver.observe(stage);
      }

      document.addEventListener('visibilitychange', () => {
        running = !document.hidden;
      });

      if (!reduceMotion.matches && window.matchMedia('(pointer: fine)').matches) {
        stage.parentElement.addEventListener('pointermove', (event) => {
          if (event.pointerType === 'touch') return;
          const rect = stage.parentElement.getBoundingClientRect();
          pointer.x = (event.clientX - rect.left) / rect.width - 0.5;
          pointer.y = (event.clientY - rect.top) / rect.height - 0.5;
        }, { passive: true });

        stage.parentElement.addEventListener('pointerleave', () => {
          pointer.x = 0;
          pointer.y = 0;
        });
      }

      renderer.setAnimationLoop(loop);
      if (reduceMotion.matches) {
        render();
        renderer.setAnimationLoop(null);
      }

      reduceMotion.addEventListener('change', (event) => {
        if (event.matches) {
          render();
          renderer.setAnimationLoop(null);
        } else {
          renderer.setAnimationLoop(loop);
        }
      });
    } catch (error) {
      stage.classList.add('is-fallback');
      console.warn('open-admin hero scene unavailable', error);
    }
  };

  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(start, { timeout: 1200 });
  } else {
    window.setTimeout(start, 220);
  }
}
