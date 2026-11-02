#include <GLES3/gl32.h>
#include <stddef.h>

static int stub_gl_version(void) { return 32; }
static int stub_is_desktop_gl(void) { return 0; }

// Function pointer variables matching libepoxy's memory layout
void (*epoxy_glActiveTexture)(GLenum) = glActiveTexture;
void (*epoxy_glAttachShader)(GLuint, GLuint) = glAttachShader;
void (*epoxy_glBindBuffer)(GLenum, GLuint) = glBindBuffer;
void (*epoxy_glBindBufferBase)(GLenum, GLuint, GLuint) = glBindBufferBase;
void (*epoxy_glBindFramebuffer)(GLenum, GLuint) = glBindFramebuffer;
void (*epoxy_glBindTexture)(GLenum, GLuint) = glBindTexture;
void (*epoxy_glBindVertexArray)(GLuint) = glBindVertexArray;
void (*epoxy_glBlendFuncSeparate)(GLenum, GLenum, GLenum, GLenum) = glBlendFuncSeparate;
void (*epoxy_glBufferData)(GLenum, GLsizeiptr, const void*, GLenum) = glBufferData;
void (*epoxy_glBufferSubData)(GLenum, GLintptr, GLsizeiptr, const void*) = glBufferSubData;
GLenum (*epoxy_glCheckFramebufferStatus)(GLenum) = glCheckFramebufferStatus;
void (*epoxy_glClear)(GLbitfield) = glClear;
void (*epoxy_glClearColor)(GLfloat, GLfloat, GLfloat, GLfloat) = glClearColor;
void (*epoxy_glCompileShader)(GLuint) = glCompileShader;
void (*epoxy_glCopyTexSubImage2D)(GLenum, GLint, GLint, GLint, GLint, GLint, GLsizei, GLsizei) = glCopyTexSubImage2D;
void (*epoxy_glCopyTexSubImage3D)(GLenum, GLint, GLint, GLint, GLint, GLint, GLint, GLsizei, GLsizei) = glCopyTexSubImage3D;
GLuint (*epoxy_glCreateProgram)(void) = glCreateProgram;
GLuint (*epoxy_glCreateShader)(GLenum) = glCreateShader;
void (*epoxy_glDeleteBuffers)(GLsizei, const GLuint*) = glDeleteBuffers;
void (*epoxy_glDeleteFramebuffers)(GLsizei, const GLuint*) = glDeleteFramebuffers;
void (*epoxy_glDeleteProgram)(GLuint) = glDeleteProgram;
void (*epoxy_glDeleteShader)(GLuint) = glDeleteShader;
void (*epoxy_glDeleteTextures)(GLsizei, const GLuint*) = glDeleteTextures;
void (*epoxy_glDeleteVertexArrays)(GLsizei, const GLuint*) = glDeleteVertexArrays;
void (*epoxy_glDisable)(GLenum) = glDisable;
void (*epoxy_glDrawArrays)(GLenum, GLint, GLsizei) = glDrawArrays;
void (*epoxy_glDrawArraysInstanced)(GLenum, GLint, GLsizei, GLsizei) = glDrawArraysInstanced;
void (*epoxy_glEnable)(GLenum) = glEnable;
void (*epoxy_glFinish)(void) = glFinish;
void (*epoxy_glFramebufferTexture2D)(GLenum, GLenum, GLenum, GLuint, GLint) = glFramebufferTexture2D;
void (*epoxy_glFramebufferTextureLayer)(GLenum, GLenum, GLuint, GLint, GLint) = glFramebufferTextureLayer;
void (*epoxy_glGenBuffers)(GLsizei, GLuint*) = glGenBuffers;
void (*epoxy_glGenFramebuffers)(GLsizei, GLuint*) = glGenFramebuffers;
void (*epoxy_glGenTextures)(GLsizei, GLuint*) = glGenTextures;
void (*epoxy_glGenVertexArrays)(GLsizei, GLuint*) = glGenVertexArrays;
GLenum (*epoxy_glGetError)(void) = glGetError;
void (*epoxy_glGetIntegerv)(GLenum, GLint*) = glGetIntegerv;
void (*epoxy_glGetProgramInfoLog)(GLuint, GLsizei, GLsizei*, char*) = glGetProgramInfoLog;
void (*epoxy_glGetProgramiv)(GLuint, GLenum, GLint*) = glGetProgramiv;
void (*epoxy_glGetShaderInfoLog)(GLuint, GLsizei, GLsizei*, char*) = glGetShaderInfoLog;
void (*epoxy_glGetShaderiv)(GLuint, GLenum, GLint*) = glGetShaderiv;
const GLubyte* (*epoxy_glGetString)(GLenum) = glGetString;
GLuint (*epoxy_glGetUniformBlockIndex)(GLuint, const char*) = glGetUniformBlockIndex;
GLint (*epoxy_glGetUniformLocation)(GLuint, const char*) = glGetUniformLocation;
void (*epoxy_glLinkProgram)(GLuint) = glLinkProgram;
void (*epoxy_glPixelStorei)(GLenum, GLint) = glPixelStorei;
void (*epoxy_glReadPixels)(GLint, GLint, GLsizei, GLsizei, GLenum, GLenum, void*) = glReadPixels;
void (*epoxy_glShaderSource)(GLuint, GLsizei, const char* const*, const GLint*) = glShaderSource;
void (*epoxy_glTexBuffer)(GLenum, GLenum, GLuint) = glTexBuffer;
void (*epoxy_glTexImage2D)(GLenum, GLint, GLint, GLsizei, GLsizei, GLint, GLenum, GLenum, const void*) = glTexImage2D;
void (*epoxy_glTexImage3D)(GLenum, GLint, GLint, GLsizei, GLsizei, GLsizei, GLint, GLenum, GLenum, const void*) = glTexImage3D;
void (*epoxy_glTexParameterfv)(GLenum, GLenum, const GLfloat*) = glTexParameterfv;
void (*epoxy_glTexParameteri)(GLenum, GLenum, GLint) = glTexParameteri;
void (*epoxy_glTexSubImage2D)(GLenum, GLint, GLint, GLint, GLsizei, GLsizei, GLenum, GLenum, const void*) = glTexSubImage2D;
void (*epoxy_glTexSubImage3D)(GLenum, GLint, GLint, GLint, GLint, GLsizei, GLsizei, GLsizei, GLenum, GLenum, const void*) = glTexSubImage3D;
void (*epoxy_glUniform1f)(GLint, GLfloat) = glUniform1f;
void (*epoxy_glUniform1i)(GLint, GLint) = glUniform1i;
void (*epoxy_glUniform2f)(GLint, GLfloat, GLfloat) = glUniform2f;
void (*epoxy_glUniform4i)(GLint, GLint, GLint, GLint, GLint) = glUniform4i;
void (*epoxy_glUniformBlockBinding)(GLuint, GLuint, GLuint) = glUniformBlockBinding;
void (*epoxy_glUseProgram)(GLuint) = glUseProgram;
void (*epoxy_glViewport)(GLint, GLint, GLsizei, GLsizei) = glViewport;
int (*epoxy_gl_version)(void) = stub_gl_version;
int (*epoxy_is_desktop_gl)(void) = stub_is_desktop_gl;
