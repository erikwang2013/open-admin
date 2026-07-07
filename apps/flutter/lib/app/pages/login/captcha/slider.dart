// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import '../../../services/captcha_service.dart';

const _sw = 300.0, _sh = 200.0;

class SliderCaptcha extends StatefulWidget {
  final CaptchaData data;
  const SliderCaptcha({super.key, required this.data});
  @override
  State<SliderCaptcha> createState() => SliderCaptchaState();
}

class SliderCaptchaState extends State<SliderCaptcha> {
  double get answer => val;
  double val = 0;

  @override
  Widget build(BuildContext c) {
    final d = widget.data;
    final p = d.puzzleBytes;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text('拖动拼图片对齐缺口', style: const TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      AspectRatio(aspectRatio: _sw / _sh, child: LayoutBuilder(builder: (_, box) {
        final w = box.maxWidth, h = box.maxHeight;
        final pw = w * d.puzzleW / _sw;
        final ph = h * d.puzzleH / _sh;
        return ClipRRect(borderRadius: BorderRadius.circular(8), child: Stack(children: [
          Image.memory(d.imageBytes, width: w, height: h, fit: BoxFit.fill),
          if (p != null)
            Positioned(
              left: (val / _sw * w).clamp(0, w - pw),
              top: d.sliderY / _sh * h,
              child: Image.memory(p, width: pw, height: ph, fit: BoxFit.fill),
            ),
        ]));
      })),
      const SizedBox(height: 10),
      // 自定义拖拽轨道，避免与 SingleChildScrollView 的手势冲突
      LayoutBuilder(builder: (_, tb) {
        final trackW = tb.maxWidth;
        const thumbSize = 40.0;
        final track = trackW - thumbSize;
        final frac = track > 0 ? val / _sw : 0.0;
        final thumbX = frac * track;
        return GestureDetector(
          onHorizontalDragStart: (d) {
            final box = context.findRenderObject() as RenderBox?;
            if (box == null) return;
            final dx = (box.globalToLocal(d.globalPosition).dx - thumbSize / 2).clamp(0.0, track);
            setState(() => val = dx / track * _sw);
          },
          onHorizontalDragUpdate: (d) {
            final box = context.findRenderObject() as RenderBox?;
            if (box == null) return;
            final dx = (box.globalToLocal(d.globalPosition).dx - thumbSize / 2).clamp(0.0, track);
            setState(() => val = dx / track * _sw);
          },
          child: Container(
            height: 40,
            decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(20)),
            child: Stack(children: [
              Positioned(
                left: thumbX,
                top: 2,
                child: Container(
                  width: 36, height: 36,
                  decoration: const BoxDecoration(color: Color(0xFF1677FF), shape: BoxShape.circle),
                  child: const Icon(Icons.arrow_forward, color: Colors.white, size: 20),
                ),
              ),
            ]),
          ),
        );
      }),
    ]);
  }
}
