import vk_api
import time
import json
import random

# ===== НАСТРОЙКИ =====
# ЭТО ДОЛЖЕН БЫТЬ ТОКЕН ВАШЕГО СООБЩЕСТВА (из настроек группы)
GROUP_TOKEN = "vk1.a.QZSk_QlUxdVEB93kPqGY0btk0dlT87HRTryDL-M3UOM0wdOHbcM2hv1Nvu57FsEdGFKegxOhsQfJJ8jviKqTlfYNJrtOJY4mS2nqV1GRUhwUYsjBdxw7_vQr2-D99CdIT1thjn8t2hJRHPmMZ63zrNchvVCj6oyJAyVlt2Xz_2ZB1QjilDth8XCZx1mLN-ikV-K6pRr5RIUshDWDsKUgVA"

# ID вашего сообщества (например, 123456789)
MY_GROUP_ID = 238678256

# Загрузка ID сообществ, на которые будем подписывать НАШЕ сообщество
with open("active_groups.txt", "r", encoding="utf-8") as f:
    TARGET_GROUPS = [int(line.strip()) for line in f if line.strip()]

# ===== ПОДКЛЮЧЕНИЕ =====
vk_session = vk_api.VkApi(token=GROUP_TOKEN)
vk = vk_session.get_api()

# ===== ПОДПИСКА СООБЩЕСТВА НА СООБЩЕСТВО =====
def safe_follow_group(group_id):
    """
    Наше сообщество подписывается на другое сообщество
    group_id: ID сообщества, на которое подписываемся (положительное число)
    """
    target = abs(group_id)  # API ожидает положительное число
    
    try:
        # Пробуем отписаться, если уже подписаны (необязательно)
        # vk.groups.unfollow(group_id=target)
        
        # Подписываем наше сообщество на другое
        response = vk.groups.follow(group_id=target)
        return True, response
    except vk_api.exceptions.ApiError as e:
        error_msg = str(e)
        
        if "already followed" in error_msg.lower():
            return False, "Уже подписано"
        elif "access denied" in error_msg.lower():
            return False, "Нет доступа (возможно, это группа, а не публичная страница)"
        elif "group id" in error_msg.lower():
            return False, "Неверный ID сообщества"
        else:
            return False, f"Ошибка: {error_msg[:80]}"

# ===== ЗАПУСК =====
print(f"📋 Сообществ в списке: {len(TARGET_GROUPS)}")
print(f"⚠️ Лимит ВК: не более 100 подписок в сутки для сообщества")
print(f"🤖 Подписывает: сообщество с ID {MY_GROUP_ID}")
print()

followed = 0
skipped = 0
failed = 0

for idx, group_id in enumerate(TARGET_GROUPS):
    if followed >= 100:
        print(f"\n⛔ Дневной лимит (100) достигнут. Останов.")
        break
    
    print(f"[{idx+1}/{len(TARGET_GROUPS)}] Подписываем сообщество на {group_id}...")
    
    success, message = safe_follow_group(group_id)
    
    if success:
        followed += 1
        print(f"   ✅ Подписались! (Сегодня: {followed})")
    elif "Уже подписано" in message:
        skipped += 1
        print(f"   ⏭️ {message}")
    else:
        failed += 1
        print(f"   ❌ {message}")
    
    # Пауза между подписками (20-40 секунд для безопасности)
    if idx < len(TARGET_GROUPS) - 1 and followed < 100:
        pause = random.randint(20, 40)
        print(f"   ⏸️ Пауза {pause} секунд...\n")
        time.sleep(pause)

print(f"\n✅ Подписались: {followed}")
print(f"⏭️ Уже были: {skipped}")
print(f"❌ Ошибок: {failed}")