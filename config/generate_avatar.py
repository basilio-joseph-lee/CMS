# generate_avatar.py
# Detects face type and hair type only; simplified version for debugging

import sys
import cv2
import numpy as np
import mediapipe as mp
import json

# === Validate args ===
if len(sys.argv) < 2:
    print("Usage: python generate_avatar.py <face_image_path> [output_metadata_path]")
    sys.exit(1)

face_image_path = sys.argv[1]
output_metadata_path = sys.argv[2] if len(sys.argv) >= 3 else None

# === Load image ===
img = cv2.imread(face_image_path)
if img is None:
    print("ERROR: Cannot load image")
    sys.exit(1)

height, width, _ = img.shape

# === Use MediaPipe to detect face landmarks ===
mp_face_mesh = mp.solutions.face_mesh
face_mesh = mp_face_mesh.FaceMesh(static_image_mode=True, max_num_faces=1)
results = face_mesh.process(cv2.cvtColor(img, cv2.COLOR_BGR2RGB))

if not results.multi_face_landmarks:
    print("ERROR: No face detected")
    sys.exit(1)

landmarks = results.multi_face_landmarks[0].landmark

# === Gender estimation (based on jaw width) ===
def estimate_gender():
    left_jaw = landmarks[234]
    right_jaw = landmarks[454]
    jaw_width = abs(right_jaw.x - left_jaw.x) * width
    print("Jaw width:", jaw_width)
    return "male" if jaw_width > 150 else "female"

gender = estimate_gender()
print("Estimated Gender:", gender)

# === Hair region sampling ===
hair_region = img[int(height * 0.05):int(height * 0.25), int(width * 0.3):int(width * 0.7)]

def dominant_color(region):
    pixels = region.reshape(-1, 3)
    pixels = np.float32(pixels)
    n_colors = 1
    _, labels, palette = cv2.kmeans(pixels, n_colors, None,
                                    (cv2.TERM_CRITERIA_EPS + cv2.TERM_CRITERIA_MAX_ITER, 10, 1.0),
                                    10, cv2.KMEANS_RANDOM_CENTERS)
    return palette[0].astype(int)

def color_to_name(rgb):
    r, g, b = rgb
    if r > 180 and g < 80 and b < 80:
        return "red"
    elif r < 80 and g > 180 and b < 80:
        return "blue"
    elif r < 80 and g < 80 and b > 180:
        return "blue"
    elif r > 180 and g > 180 and b < 100:
        return "yellow"
    elif r > 200 and g > 200 and b > 200:
        return "white"
    elif r < 50 and g < 50 and b < 50:
        return "black"
    else:
        return "black"

hair_rgb = dominant_color(hair_region)
hair_color = color_to_name(hair_rgb)
print("Hair color RGB:", hair_rgb)
print("Detected Hair Color:", hair_color)

# === Save metadata to JSON if output path is provided ===
if output_metadata_path:
    metadata = {
        "gender": gender,
        "hair_color": hair_color
    }
    with open(output_metadata_path, 'w') as f:
        json.dump(metadata, f)

# Done: now we can pass these values to any generator manually
print("OK")
