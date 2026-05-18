import vk_api
import time
import json
import random

# ===== НАСТРОЙКИ =====
VK_TOKEN = "vk1.a.5M8ival1fk-TVzjK9Wi27Rs8UHWJY-hLKOAxTnHhnXKIsdvQT6Dn0TqDXjmPP1PLMCNbehlYnbQIgfXrUUvZIwlr7_58fuGSQzPKCz5a-ChnVudcI3GlXT_0rLoTZdtMr5VglLNda55pmWc4v0MsW4ewYFsxLvbbuASIBpWvTPvVZyn4QARvhpZrIsb0LlX1MXj7lHjzqisKlD7fUIdMwA"
MY_GROUP_ID = 238678256   # ID вашего сообщества (которое будет вступать)

# Загрузка ID сообществ для подписки (формат: один ID на строку)
with open("active_groups.txt", "r", encoding="utf-8") as f:
    TARGET_GROUPS = [int(line.strip()) for line in f if line.strip()]

# ===== ПОДКЛЮЧЕНИЕ =====
vk_session = vk_api.VkApi(token=VK_TOKEN)
vk = vk_session.get_api()

# ===== БЕЗОПАСНАЯ ПОДПИСКА =====
def safe_join_group(group_id, my_group_id):
    """
    Безопасное вступление в группу
    group_id: ID группы, на которую подписываемся
    my_group_id: ID нашей группы (которая вступает)
    """
    # Для groups.join нужно передать group_id (может быть положительным или отрицательным)
    target = abs(group_id)  # API принимает положительное число
    
    try:
        response = vk.groups.join(
            group_id=target,
            not_sure=0  # 0 = вступить как участник
        )
        return True, response
    except vk_api.exceptions.ApiError as e:
        error_code = getattr(e, 'code', None)
        error_msg = str(e)
        
        if error_code == 15:
            return False, "Доступ в группу закрыт (нужно приглашение)"
        elif error_code == 18:
            return False, "Пользователь удален или заблокирован"
        elif "already a member" in error_msg.lower():
            return False, "Уже подписан"
        else:
            return False, f"Ошибка: {error_msg[:80]}"

# ===== ОСНОВНАЯ РАССЫЛКА =====
print(f"📋 Всего групп в списке: {len(TARGET_GROUPS)}")
print(f"⚠️ Лимит ВК: не более 50 подписок в сутки")
print()

joined = 0
skipped = 0
failed = 0

for idx, group_id in enumerate(TARGET_GROUPS):
    # Дневной лимит — защита от бана
    if joined >= 50:
        print(f"\n⛔ Достигнут суточный лимит (50 подписок). Скрипт остановлен.")
        print(f"   Завтра можно будет продолжить с того же места.")
        break
    
    print(f"[{idx+1}/{len(TARGET_GROUPS)}] Подписываемся на группу {group_id}...")
    
    success, message = safe_join_group(group_id, MY_GROUP_ID)
    
    if success:
        joined += 1
        print(f"   ✅ Успешно подписались! (Всего сегодня: {joined})")
    elif "Уже подписан" in message:
        skipped += 1
        print(f"   ⏭️ {message}")
    else:
        failed += 1
        print(f"   ❌ {message}")
    
    # Пауза между подписками (2-5 минут — безопасно)
    if idx < len(TARGET_GROUPS) - 1 and joined < 50:
        pause = random.randint(120, 300)
        minutes = pause / 60
        print(f"   ⏸️ Пауза {minutes:.1f} минут...\n")
        time.sleep(pause)

# ===== ИТОГИ =====
print("\n" + "="*50)
print("🎯 РАССЫЛКА ЗАВЕРШЕНА")
print(f"✅ Подписались: {joined}")
print(f"⏭️ Уже были подписаны: {skipped}")
print(f"❌ Не удалось: {failed}")
print(f"📊 Всего обработано: {joined + skipped + failed}")