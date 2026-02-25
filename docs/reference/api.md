# API Reference

Base URL: `/api/workflow` (configurable via `WORKFLOW_ROUTE_PREFIX`)

Tất cả routes yêu cầu authenticated user (`auth` middleware).

---

## GET /pending

Danh sách workflow instances đang chờ user hiện tại phê duyệt.

**Response**
```json
{
  "data": [
    {
      "id": "uuid",
      "status": "pending",
      "current_step": 1,
      "submitted_by": "user-uuid",
      "submitted_at": "2026-02-24T10:00:00Z",
      "metadata": { "days": 3 },
      "definition": { "id": "uuid", "name": "Phê duyệt nghỉ phép", "slug": "leave-approval" },
      "step_approvals": [...]
    }
  ],
  "total": 1,
  "current_page": 1,
  "last_page": 1
}
```

---

## GET /instances/{id}

Chi tiết 1 instance.

**Response**
```json
{
  "instance": { ... },
  "history": [ { "action": "submitted", "performed_by": "...", "created_at": "..." } ],
  "approvals": [ { "step_order": 1, "status": "pending", "approver_role": "manager" } ]
}
```

---

## POST /instances/{id}/approve

**Body**
```json
{ "comment": "Đồng ý" }
```

**Response** `200`
```json
{ "message": "Đã phê duyệt thành công." }
```

**Errors**
- `422` — approver không đúng role, hoặc instance đã complete

---

## POST /instances/{id}/reject

**Body** (comment bắt buộc)
```json
{ "comment": "Thiếu giấy tờ" }
```

**Response** `200`
```json
{ "message": "Đã từ chối." }
```

---

## POST /instances/{id}/cancel

Chỉ submitter hoặc user có role `admin` mới được hủy.

**Body** (reason bắt buộc)
```json
{ "reason": "Hủy theo yêu cầu" }
```

**Response** `200`
```json
{ "message": "Đã hủy workflow." }
```

**Errors**
- `403` — không phải submitter hoặc admin
