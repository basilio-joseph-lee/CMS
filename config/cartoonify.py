import sys
import cv2
import numpy as np

def cartoonify(image_path, output_path):
    try:
        # Load the image
        img = cv2.imread(image_path)
        if img is None:
            print("ERROR: Cannot read image")
            return

        # Resize to standard avatar size
        img = cv2.resize(img, (512, 512))

        # Apply OpenCV's built-in stylization (neural-style)
        cartoon = cv2.stylization(img, sigma_s=150, sigma_r=0.3)

        # Optional: Add white background (for cleaner style)
        background = np.ones_like(cartoon, dtype=np.uint8) * 255
        gray = cv2.cvtColor(cartoon, cv2.COLOR_BGR2GRAY)
        _, mask = cv2.threshold(gray, 10, 255, cv2.THRESH_BINARY)
        result = np.where(mask[:, :, None] > 10, cartoon, background)

        # Save the result
        cv2.imwrite(output_path, result)
        print("OK")

    except Exception as e:
        print("ERROR:", str(e))

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python cartoonify_avatar.py input.jpg output.jpg")
    else:
        cartoonify(sys.argv[1], sys.argv[2])
