import vk_api
import time
import json
import random

# ===== НАСТРОЙКИ =====
VK_TOKEN = "vk1.a.QZSk_QlUxdVEB93kPqGY0btk0dlT87HRTryDL-M3UOM0wdOHbcM2hv1Nvu57FsEdGFKegxOhsQfJJ8jviKqTlfYNJrtOJY4mS2nqV1GRUhwUYsjBdxw7_vQr2-D99CdIT1thjn8t2hJRHPmMZ63zrNchvVCj6oyJAyVlt2Xz_2ZB1QjilDth8XCZx1mLN-ikV-K6pRr5RIUshDWDsKUgVA"

# Загрузка сообщений из JSON
with open("mess.json", "r", encoding="utf-8") as f:
    MESSAGES = json.load(f)

# Загрузка ID сообществ (по одному на строку)
with open("active_groups.txt", "r", encoding="utf-8") as f:
    COMMUNITY_IDS = [int(line.strip()) for line in f if line.strip()]

# ===== ПОДКЛЮЧЕНИЕ =====
vk = vk_api.VkApi(token=VK_TOKEN).get_api()

# ===== РАССЫЛКА =====
for idx, community_id in enumerate(COMMUNITY_IDS):
    # Выбираем случайное сообщение
    msg_data = random.choice(MESSAGES)
    message_text = msg_data["text"]
    
    peer_id = -abs(community_id)
    random_id = int(time.time() * 1000) + random.randint(1, 10000)
    
    try:
        vk.messages.send(
            peer_id=peer_id,
            random_id=random_id,
            message=message_text
        )
        print(f"✅ [{idx+1}/{len(COMMUNITY_IDS)}] Отправлено в сообщество {community_id}")
        print(f"   📝 Сообщение ID: {msg_data['id']}")
    except Exception as e:
        print(f"❌ [{idx+1}/{len(COMMUNITY_IDS)}] Ошибка для {community_id}: {e}")
    
    # Пауза 5-10 минут (300-600 секунд)
    if idx < len(COMMUNITY_IDS) - 1:  # после последнего не ждем
        pause = random.randint(5, 10)
        minutes = pause / 60
        print(f"⏸️ Пауза {minutes:.1f} минут...\n")
        time.sleep(pause)

print("🎯 Рассылка завершена!")