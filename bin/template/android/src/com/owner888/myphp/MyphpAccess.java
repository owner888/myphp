package com.owner888.myphp;

import android.content.Context;
import android.graphics.Rect;
import android.os.Bundle;
import android.view.MotionEvent;
import android.view.View;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityManager;
import android.view.accessibility.AccessibilityNodeInfo;
import android.view.accessibility.AccessibilityNodeInfo.AccessibilityAction;
import android.view.accessibility.AccessibilityNodeProvider;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * TalkBack reads PHP's frames: PHP gives the elements a screen reader moves through as JSON whenever they change
 * (UI\AccessElements, through myphp_access_update and setTree), and they are the virtual views of the surface, kept by
 * their node's id from frame to frame so that TalkBack's focus stays on them. Touch exploration finds them under the
 * finger (onHover); what TalkBack does to them (click, long click, scroll, a custom action, dismiss) goes back to PHP
 * as AccessNode actions (nativeAccessAction). PHP describes its frames only once a screen reader is on (nativeAccessOn).
 * Not mygo's.
 */
final class MyphpAccess extends AccessibilityNodeProvider {
    // The AccessNode bits the elements carry.
    private static final int DISABLED = 1 << 1, CHECKED = 1 << 2, MIXED = 1 << 3, PASSWORD = 1 << 7, OFFSCREEN = 1 << 10;
    private static final int PRESS = 1 << 0, FOCUS = 1 << 1, INCREMENT = 1 << 2, DECREMENT = 1 << 3, SET_VALUE = 1 << 4, SHOW_MENU = 1 << 7, DISMISS = 1 << 8;
    // AccessNode actions.
    private static final int A_PRESS = 0, A_FOCUS = 1, A_INCREMENT = 2, A_DECREMENT = 3, A_SET_VALUE = 4, A_SCROLL_INTO_VIEW = 5, A_SHOW_MENU = 10, A_CUSTOM = 11, A_DISMISS = 12;
    /** The ids of custom actions: the element's own (its swipe actions), from this one up. */
    private static final int CUSTOM_ID = 0x7f0a0000;

    private static final class Element {
        long node;
        String kind, label, value, hint;
        float x, y, w, h;
        int states, actions, level;
        float min, max, now;
        boolean ranged;
        List<String> custom = new ArrayList<>();
    }

    private final View host;
    private List<Element> elements = new ArrayList<>();
    private final Map<Integer, Element> byVirtual = new HashMap<>();
    /** The virtual view id of each node, kept while the node is, so that TalkBack's focus stays on it. */
    private final Map<Long, Integer> virtualOf = new HashMap<>();
    private int nextVirtual = 1;
    private int focused = HOST_VIEW_ID, hovered = HOST_VIEW_ID;
    private long top = 0, focus = 0;
    private String showMenu = "Show Menu";
    private boolean asked = false;

    MyphpAccess(View host) {
        this.host = host;
        AccessibilityManager am = (AccessibilityManager) host.getContext().getSystemService(Context.ACCESSIBILITY_SERVICE);
        if (am != null) {
            if (am.isEnabled() && am.isTouchExplorationEnabled()) { asked(); }
            am.addTouchExplorationStateChangeListener(on -> { if (on) { asked(); } });
        }
    }

    /** A screen reader is on, or asked for the elements: PHP describes its frames from now on. */
    void asked() {
        if (asked) { return; }
        asked = true;
        MyphpActivity.accessOn();
    }

    /** PHP's elements of the frame, as JSON (AccessElements::encode), on the UI thread. */
    void apply(byte[] json) {
        JSONObject d;
        try {
            d = new JSONObject(new String(json, StandardCharsets.UTF_8));
        } catch (JSONException e) {
            throw new RuntimeException("The elements of a frame are not JSON: " + e, e);
        }
        List<Element> next = new ArrayList<>();
        Map<Long, Integer> kept = new HashMap<>();
        byVirtual.clear();
        JSONArray els = d.optJSONArray("elements");
        for (int i = 0; els != null && i < els.length(); i++) {
            JSONObject o = els.optJSONObject(i);
            Element e = new Element();
            e.node = o.optLong("id");
            e.kind = o.optString("kind");
            e.label = o.optString("label");
            e.value = o.optString("value");
            e.hint = o.optString("hint");
            e.x = (float) o.optDouble("x"); e.y = (float) o.optDouble("y"); e.w = (float) o.optDouble("w"); e.h = (float) o.optDouble("h");
            e.states = o.optInt("states"); e.actions = o.optInt("actions"); e.level = o.optInt("level");
            e.ranged = o.has("now");
            if (e.ranged) { e.min = (float) o.optDouble("min"); e.max = (float) o.optDouble("max"); e.now = (float) o.optDouble("now"); }
            JSONArray custom = o.optJSONArray("custom");
            for (int k = 0; custom != null && k < custom.length(); k++) { e.custom.add(custom.optString(k)); }
            Integer v = virtualOf.get(e.node);
            if (v == null) { v = nextVirtual++; }
            kept.put(e.node, v);
            byVirtual.put(v, e);
            next.add(e);
        }
        virtualOf.clear();
        virtualOf.putAll(kept);
        elements = next;
        showMenu = d.optString("showMenu", showMenu);
        if (!byVirtual.containsKey(focused)) { focused = HOST_VIEW_ID; }
        long newTop = d.optLong("top"), newFocus = d.optLong("focus");
        if (newTop != top) { // a dialog or a menu came or went: another screen
            top = newTop;
            sendHost(AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED);
        } else {
            sendHost(AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED);
        }
        if (newFocus != focus && newFocus != 0 && virtualOf.containsKey(newFocus)) { send(virtualOf.get(newFocus), AccessibilityEvent.TYPE_VIEW_FOCUSED); }
        focus = newFocus;
        JSONArray say = d.optJSONArray("say");
        for (int i = 0; say != null && i < say.length(); i++) { host.announceForAccessibility(say.optString(i)); }
    }

    /** Touch exploration: the element under the finger is hovered, which TalkBack reads and focuses. */
    boolean onHover(MotionEvent ev) {
        if (!asked) { return false; }
        int v = at(ev.getX(), ev.getY());
        switch (ev.getActionMasked()) {
            case MotionEvent.ACTION_HOVER_ENTER:
            case MotionEvent.ACTION_HOVER_MOVE:
                if (v != hovered) {
                    int was = hovered;
                    hovered = v;
                    if (v != HOST_VIEW_ID) { send(v, AccessibilityEvent.TYPE_VIEW_HOVER_ENTER); }
                    if (was != HOST_VIEW_ID) { send(was, AccessibilityEvent.TYPE_VIEW_HOVER_EXIT); }
                }
                return v != HOST_VIEW_ID;
            case MotionEvent.ACTION_HOVER_EXIT:
                if (hovered != HOST_VIEW_ID) { send(hovered, AccessibilityEvent.TYPE_VIEW_HOVER_EXIT); hovered = HOST_VIEW_ID; }
                return true;
        }
        return false;
    }

    /** The element at (x, y), in the view's pixels: the last one there, as it paints over those before it. */
    private int at(float x, float y) {
        float d = density();
        int found = HOST_VIEW_ID;
        for (Element e : elements) {
            if ((e.states & OFFSCREEN) != 0) { continue; }
            if (x >= e.x * d && x < (e.x + e.w) * d && y >= e.y * d && y < (e.y + e.h) * d) { found = virtualOf.get(e.node); }
        }
        return found;
    }

    private float density() { return host.getResources().getDisplayMetrics().density; }

    @Override
    public AccessibilityNodeInfo createAccessibilityNodeInfo(int virtual) {
        asked();
        if (virtual == HOST_VIEW_ID) {
            AccessibilityNodeInfo info = new AccessibilityNodeInfo(host);
            host.onInitializeAccessibilityNodeInfo(info);
            for (Element e : elements) { info.addChild(host, virtualOf.get(e.node)); }
            return info;
        }
        Element e = byVirtual.get(virtual);
        if (e == null) { return null; }
        AccessibilityNodeInfo info = new AccessibilityNodeInfo(host, virtual);
        info.setParent(host);
        info.setPackageName(host.getContext().getPackageName());
        info.setClassName(className(e.kind));
        info.setSource(host, virtual);
        float d = density();
        Rect r = new Rect(Math.round(e.x * d), Math.round(e.y * d), Math.round((e.x + e.w) * d), Math.round((e.y + e.h) * d));
        int[] at = new int[2];
        host.getLocationOnScreen(at);
        Rect screen = new Rect(r);
        screen.offset(at[0], at[1]);
        info.setBoundsInParent(r);
        info.setBoundsInScreen(screen);
        info.setVisibleToUser((e.states & OFFSCREEN) == 0);
        boolean field = e.kind.equals("field") || e.kind.equals("search");
        if (field) {
            info.setText(e.value);
            info.setHintText(!e.label.isEmpty() ? e.label : e.hint);
            info.setEditable((e.actions & SET_VALUE) != 0);
            info.setPassword((e.states & PASSWORD) != 0);
        } else {
            info.setContentDescription(e.value.isEmpty() || e.ranged ? e.label : e.label + ", " + e.value);
            if (!e.hint.isEmpty()) { info.setHintText(e.hint); }
        }
        info.setEnabled((e.states & DISABLED) == 0);
        info.setImportantForAccessibility(true);
        info.setScreenReaderFocusable(true);
        info.setHeading(e.kind.equals("heading"));
        boolean checkable = e.kind.equals("checkbox") || e.kind.equals("switch") || e.kind.equals("radio");
        info.setCheckable(checkable);
        if (checkable) { info.setChecked((e.states & CHECKED) != 0); }
        else { info.setSelected((e.states & CHECKED) != 0); } // a tab, a row chosen
        if (e.ranged && e.now >= e.min) { // a progress of unknown length has none
            info.setRangeInfo(new AccessibilityNodeInfo.RangeInfo(AccessibilityNodeInfo.RangeInfo.RANGE_TYPE_FLOAT, e.min, e.max, e.now));
        }
        info.setAccessibilityFocused(focused == virtual);
        info.addAction(focused == virtual ? AccessibilityAction.ACTION_CLEAR_ACCESSIBILITY_FOCUS : AccessibilityAction.ACTION_ACCESSIBILITY_FOCUS);
        if ((e.actions & (PRESS | FOCUS)) != 0 || field) { info.setClickable(true); info.addAction(AccessibilityAction.ACTION_CLICK); }
        if ((e.actions & SHOW_MENU) != 0) { info.setLongClickable(true); info.addAction(new AccessibilityAction(AccessibilityNodeInfo.ACTION_LONG_CLICK, showMenu)); }
        if ((e.actions & INCREMENT) != 0) { info.addAction(AccessibilityAction.ACTION_SCROLL_FORWARD); }
        if ((e.actions & DECREMENT) != 0) { info.addAction(AccessibilityAction.ACTION_SCROLL_BACKWARD); }
        if ((e.actions & DISMISS) != 0) { info.setDismissable(true); info.addAction(AccessibilityAction.ACTION_DISMISS); }
        for (int k = 0; k < e.custom.size(); k++) { info.addAction(new AccessibilityAction(CUSTOM_ID + k, e.custom.get(k))); }
        return info;
    }

    @Override
    public boolean performAction(int virtual, int action, Bundle args) {
        if (virtual == HOST_VIEW_ID) { return host.performAccessibilityAction(action, args); }
        Element e = byVirtual.get(virtual);
        if (e == null) { return false; }
        switch (action) {
            case AccessibilityNodeInfo.ACTION_ACCESSIBILITY_FOCUS:
                if (focused == virtual) { return false; }
                int was = focused;
                focused = virtual;
                if (was != HOST_VIEW_ID) { send(was, AccessibilityEvent.TYPE_VIEW_ACCESSIBILITY_FOCUS_CLEARED); }
                send(virtual, AccessibilityEvent.TYPE_VIEW_ACCESSIBILITY_FOCUSED);
                if ((e.states & OFFSCREEN) != 0) { MyphpActivity.accessAction(e.node, A_SCROLL_INTO_VIEW, 0); } // reached out of view: it scrolls into it
                host.invalidate();
                return true;
            case AccessibilityNodeInfo.ACTION_CLEAR_ACCESSIBILITY_FOCUS:
                if (focused != virtual) { return false; }
                focused = HOST_VIEW_ID;
                send(virtual, AccessibilityEvent.TYPE_VIEW_ACCESSIBILITY_FOCUS_CLEARED);
                return true;
            case AccessibilityNodeInfo.ACTION_CLICK:
                if (e.kind.equals("field") || e.kind.equals("search")) { // tapped, as a finger would, which brings its keyboard
                    float d = density(), cx = (e.x + e.w / 2) * d, cy = (e.y + e.h / 2) * d;
                    long t = android.os.SystemClock.uptimeMillis();
                    MyphpActivity.touch(MotionEvent.ACTION_DOWN, cx, cy, t);
                    MyphpActivity.touch(MotionEvent.ACTION_UP, cx, cy, t + 1);
                    return true;
                }
                if ((e.actions & PRESS) != 0) { MyphpActivity.accessAction(e.node, A_PRESS, 0); return true; }
                if ((e.actions & FOCUS) != 0) { MyphpActivity.accessAction(e.node, A_FOCUS, 0); return true; }
                return false;
            case AccessibilityNodeInfo.ACTION_LONG_CLICK:
                if ((e.actions & SHOW_MENU) == 0) { return false; }
                MyphpActivity.accessAction(e.node, A_SHOW_MENU, 0);
                return true;
            case AccessibilityNodeInfo.ACTION_SCROLL_FORWARD:
                if ((e.actions & INCREMENT) == 0) { return false; }
                MyphpActivity.accessAction(e.node, A_INCREMENT, 0);
                return true;
            case AccessibilityNodeInfo.ACTION_SCROLL_BACKWARD:
                if ((e.actions & DECREMENT) == 0) { return false; }
                MyphpActivity.accessAction(e.node, A_DECREMENT, 0);
                return true;
            case AccessibilityNodeInfo.ACTION_DISMISS:
                if ((e.actions & DISMISS) == 0) { return false; }
                MyphpActivity.accessAction(e.node, A_DISMISS, 0);
                return true;
        }
        if (action >= CUSTOM_ID && action < CUSTOM_ID + e.custom.size()) {
            MyphpActivity.accessAction(e.node, A_CUSTOM, action - CUSTOM_ID);
            return true;
        }
        return false;
    }

    /** The class TalkBack names an element of a kind by, as Android's own widgets'. */
    private static String className(String kind) {
        switch (kind) {
            case "button": case "popup": return "android.widget.Button";
            case "tab": return "android.widget.TabWidget";
            case "checkbox": return "android.widget.CheckBox";
            case "radio": return "android.widget.RadioButton";
            case "switch": return "android.widget.Switch";
            case "slider": return "android.widget.SeekBar";
            case "progress": return "android.widget.ProgressBar";
            case "field": case "search": return "android.widget.EditText";
            case "image": return "android.widget.ImageView";
            default: return "android.view.View";
        }
    }

    private void send(int virtual, int type) {
        AccessibilityEvent ev = new AccessibilityEvent(type);
        ev.setPackageName(host.getContext().getPackageName());
        ev.setSource(host, virtual);
        Element e = byVirtual.get(virtual);
        if (e != null) { ev.setContentDescription(e.label); ev.setClassName(className(e.kind)); }
        if (host.getParent() != null) { host.getParent().requestSendAccessibilityEvent(host, ev); }
    }

    private void sendHost(int type) {
        AccessibilityEvent ev = new AccessibilityEvent(type);
        ev.setPackageName(host.getContext().getPackageName());
        ev.setSource(host);
        if (type == AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED) { ev.setContentChangeTypes(AccessibilityEvent.CONTENT_CHANGE_TYPE_SUBTREE); }
        if (host.getParent() != null) { host.getParent().requestSendAccessibilityEvent(host, ev); }
    }
}
